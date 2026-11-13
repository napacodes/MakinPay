<?php

namespace App\Services\Observability;

use App\Models\Observability\ErrorEvent;
use App\Models\Observability\ErrorGroup;

/**
 * Builds the debug report a developer works from to produce a fix.
 *
 * Built at export time rather than stored, so tightening a redaction rule
 * applies retroactively to every future export instead of only to errors
 * captured afterwards.
 *
 * Two decisions here matter more than the format.
 *
 * The root-cause frame is labelled a candidate everywhere it appears. It is a
 * heuristic — the first application frame from the top — and a reader who
 * treats a heuristic as a fact patches the wrong file with total confidence.
 *
 * The redaction report travels inside the report. A reader who knows a field
 * was removed asks for it; one who simply cannot see it assumes a
 * plausible value and builds on that.
 */
class DebugReportBuilder
{
    /**
     * Size budget.
     *
     * A report is read by a person or pasted into a tool, and both have a
     * limit. Before these caps a single error produced roughly thirty
     * thousand characters, most of it framework frames nobody can act on.
     */
    private const MAX_APP_FRAMES = 12;

    private const MAX_VENDOR_FRAMES = 4;

    private const MAX_STRING = 240;

    private const MAX_KEYS = 25;

    private const MAX_DEPTH = 4;

    private const MAX_DIFF_FILES = 8;

    /**
     * The packages named in the trace are the ones a fix might have to pin.
     * The rest of the lock file is a list nobody reads, and every line of it
     * competes with the excerpt for whatever budget the report is read under.
     */
    private const MAX_PACKAGES = 12;

    /**
     * The structured bundle. The markdown rendering is a view over this, so
     * the two can never disagree about what was included.
     *
     * @return array<string, mixed>
     */
    public function build(ErrorGroup $group, ?ErrorEvent $event = null): array
    {
        $event ??= $group->events()->latest('created_at')->first();

        $frames    = (array) data_get($event?->context, 'stack', []);
        $rootCause = $this->rootCauseCandidate($frames);

        return [
            'summary'          => $this->summary($group, $event),
            'root_cause_frame' => $rootCause === null ? null : $rootCause + [
                'confidence' => 'candidate',
                'caveat'     => 'Heuristic: the first application frame from the top of the trace. Verify before changing it.',
            ],
            'code_excerpt'     => data_get($event?->context, 'code_excerpt'),
            'stack'            => $this->compactStack($frames),
            'request'          => $this->clipPayload((array) $event?->request),
            'state'            => $this->clipPayload((array) $event?->state),
            'environment'      => $this->environment($event),
            'deploy_diff'      => $this->deployDiff($frames),
            'redaction_report' => (array) $event?->redaction_report,
            'fix_checklist'    => $this->fixChecklist($group),
        ];
    }

    /**
     * Whether a strict export must refuse.
     *
     * A field the pipeline could not classify was dropped at capture, but its
     * presence means the bundle's completeness cannot be vouched for. Saying
     * so beats shipping it quietly.
     */
    public function isBlocked(?ErrorEvent $event): bool
    {
        return (int) data_get($event?->redaction_report, 'unclassified', 0) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ErrorGroup $group, ?ErrorEvent $event): array
    {
        return [
            'group_id'        => $group->group_id,
            'title'           => $group->title,
            'exception_class' => $group->exception_class,
            'message'         => $this->clip($group->message),
            'error_code'      => $group->error_code,
            'level'           => $group->level->value,
            'source'          => $group->source->value,
            'status'          => $group->status->value,
            'culprit'         => $group->culprit,
            'first_seen'      => $group->first_seen?->toIso8601String(),
            'last_seen'       => $group->last_seen?->toIso8601String(),
            'event_count'     => $group->event_count,
            'affected_users'  => $group->affected_user_count,
            'trace_id'        => $event?->trace_id,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>> $frames
     * @return array<string, mixed>|null
     */
    private function rootCauseCandidate(array $frames): ?array
    {
        foreach ($frames as $frame) {
            if (($frame['is_app'] ?? false) === true) {
                return [
                    'file'     => $frame['file']     ?? null,
                    'line'     => $frame['line']     ?? null,
                    'function' => $frame['function'] ?? null,
                    'class'    => $frame['class']    ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Application frames whole, vendor frames summarised.
     *
     * A real trace on this platform runs to eighty-odd frames, and the vendor
     * ones are eighty per cent of the bytes. They are also the frames nobody
     * can act on: the fix is never inside the framework. Keeping every one of
     * them turned a report into thirty thousand characters, which is enough to
     * stall the tooling it was meant to feed.
     *
     * So: every application frame up to a sane cap, the handful of vendor
     * frames immediately around them for context, and a single line standing
     * in for the rest. The count is stated rather than hidden, because a
     * reader who knows frames were dropped can ask for them.
     *
     * @param  array<int, array<string, mixed>> $frames
     * @return array<int, array<string, mixed>>
     */
    private function compactStack(array $frames): array
    {
        $appFrames = array_values(array_filter(
            $frames,
            static fn (array $frame): bool => ($frame['is_app'] ?? false) === true
        ));

        $vendorCount = count($frames) - count($appFrames);

        $kept = array_map(
            fn (array $frame): array => $this->compactFrame($frame),
            array_slice($appFrames, 0, self::MAX_APP_FRAMES)
        );

        $droppedApp = max(0, count($appFrames) - self::MAX_APP_FRAMES);

        /*
         * The first vendor frames after the application code are where the
         * call left our control, which is often the clue. The rest are not.
         */
        $vendorContext = array_values(array_filter(
            $frames,
            static fn (array $frame): bool => ($frame['is_app'] ?? false) !== true
        ));

        foreach (array_slice($vendorContext, 0, self::MAX_VENDOR_FRAMES) as $frame) {
            $kept[] = $this->compactFrame($frame) + ['vendor' => true];
        }

        $omitted = $droppedApp + max(0, $vendorCount - self::MAX_VENDOR_FRAMES);

        if ($omitted > 0) {
            $kept[] = [
                'omitted' => $omitted,
                'note'    => $omitted.' further frames omitted to keep this report small. '
                    .'They are vendor and framework internals; open the error in the admin panel for the full trace.',
            ];
        }

        return $kept;
    }

    /**
     * One frame, reduced to what identifies it.
     *
     * Arguments are dropped rather than truncated. They were already scrubbed
     * at capture, so what survives is mostly placeholder text, and it is bulk
     * without information.
     *
     * @param  array<string, mixed> $frame
     * @return array<string, mixed>
     */
    private function compactFrame(array $frame): array
    {
        return array_filter([
            'file'     => $this->relativePath($frame['file'] ?? null),
            'line'     => $frame['line']     ?? null,
            'function' => $frame['function'] ?? null,
            'class'    => $frame['class']    ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Paths relative to the project root, with forward slashes.
     *
     * Frames are captured as absolute paths, which on a Windows workstation
     * means every line of the report carries "E:\www\digikash\" and the
     * directory layout of whoever ran it. Stripping the root shortens the
     * report and keeps a local filesystem out of a document that gets pasted
     * elsewhere.
     */
    private function relativePath(?string $file): ?string
    {
        if ($file === null || $file === '') {
            return $file;
        }

        $root = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', base_path()), '/').'/';
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Shorten a value that has no business being long in a report.
     */
    private function clip(mixed $value, int $limit = self::MAX_STRING): mixed
    {
        if (! is_string($value) || mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'… ['.mb_strlen($value).' chars, truncated]';
    }

    /**
     * Walk a payload, clipping long strings and refusing to recurse forever.
     *
     * Request bodies and job payloads are arbitrary shapes from anywhere in
     * the platform; without a depth stop one deeply nested provider response
     * can outweigh the entire rest of the report.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function clipPayload(array $payload, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['…' => 'nested further; omitted from this report'];
        }

        $out = [];

        foreach (array_slice($payload, 0, self::MAX_KEYS, true) as $key => $value) {
            $out[$key] = is_array($value)
                ? $this->clipPayload($value, $depth + 1)
                : $this->clip($value);
        }

        if (count($payload) > self::MAX_KEYS) {
            $out['…'] = (count($payload) - self::MAX_KEYS).' further keys omitted';
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(?ErrorEvent $event): array
    {
        return [
            'release'     => data_get($event?->context, 'release'),
            'commit_sha'  => data_get($event?->context, 'commit_sha'),
            'branch'      => data_get($event?->context, 'branch'),
            'environment' => data_get($event?->context, 'environment'),
            'php'         => data_get($event?->context, 'php'),
            'laravel'     => data_get($event?->context, 'laravel'),
            'packages'    => array_slice((array) data_get($event?->context, 'packages', []), 0, self::MAX_PACKAGES, true),
        ];
    }

    /**
     * Which files in the trace are worth diffing.
     *
     * The deploy diff is filtered to files that appear in the trace. A
     * forty-file release diff buries the three lines that matter.
     *
     * @param  array<int, array<string, mixed>> $frames
     * @return array<string, mixed>
     */
    private function deployDiff(array $frames): array
    {
        $files = array_values(array_unique(array_filter(array_map(
            fn (array $frame): ?string => ($frame['is_app'] ?? false) === true
                ? $this->relativePath($frame['file'] ?? null)
                : null,
            $frames
        ))));

        /*
         * Capped: a request that passes through a dozen middleware lists a
         * dozen files, none of which is where the bug lives. The frames
         * nearest the throw are the ones worth opening.
         */
        $files = array_slice($files, 0, self::MAX_DIFF_FILES);

        return [
            'files' => $files,
            'note'  => 'Diff limited to application files appearing in this trace. A full release diff drowns the signal.',
        ];
    }

    /**
     * The fix checklist carried at the top of the report.
     *
     * It states the house rules a fix for this codebase has to respect, so the
     * developer picking the report up is not rediscovering them from the
     * engineering guidelines each time. It is a checklist, not instructions to
     * anything — the report is read by a person.
     */
    private function fixChecklist(ErrorGroup $group): string
    {
        return implode("\n", [
            'Error: '.$group->title.' ('.($group->error_code ?? 'no registry code').')',
            '',
            'Before the fix:',
            '- The root-cause frame below is a candidate, not a conclusion. Confirm it against the trace.',
            '- Fields marked DROPPED or MASKED were removed before this report was built. Reproduce them from a safe test fixture rather than assuming a value.',
            '',
            'The fix itself:',
            '- Change the cause, not the symptom, and keep the change as small as it can be.',
            '- Add one regression test that fails before the fix and passes after it.',
            '- Do not refactor anything unrelated to this error.',
            '- Do not weaken or delete an existing test to make the fix pass.',
            '- Money handling stays atomic and must not use floating-point arithmetic.',
        ]);
    }

    /**
     * The markdown rendering, built from the same array the JSON export uses,
     * so the two can never disagree about what was included.
     */
    public function toMarkdown(ErrorGroup $group, ?ErrorEvent $event = null): string
    {
        $bundle = $this->build($group, $event);
        $report = $bundle['redaction_report'];

        $sections = [
            '# '.$bundle['summary']['title'],
            '',
            '> Redaction report: '.(int) ($report['dropped'] ?? 0).' dropped, '.(int) ($report['masked'] ?? 0).' masked.',
            '> This report is deliberately bounded: application frames in full, framework frames summarised.',
            '> Anything listed as removed is not available in this report. Reproduce it from a safe test fixture; do not assume a value.',
            '',
            '## How to fix this',
            '',
            $bundle['fix_checklist'],
            '',
            $this->jsonSection('Summary', $bundle['summary']),
            $this->jsonSection('Root cause candidate', $bundle['root_cause_frame']),
            '## Source at the failing line, as deployed',
            '',
            '```php',
            $this->renderExcerpt($bundle['code_excerpt']),
            '```',
            '',
            $this->jsonSection('Environment', $bundle['environment']),
            $this->jsonSection('Request and state', ['request' => $bundle['request'], 'state' => $bundle['state']]),
            '## Stack',
            '',
            'Application frames are marked ->; framework frames are indented.',
            '',
            '```',
            $this->renderStack($bundle['stack']),
            '```',
            '',
            '## Files to diff',
            '',
            $this->renderDiffTargets($bundle['deploy_diff']),
            '',
            $this->jsonSection('What was redacted', $report),
        ];

        return implode("\n", $sections);
    }

    /**
     * The stack as plain lines rather than pretty-printed JSON.
     *
     * A frame is a path, a line and a function; wrapping that in JSON braces
     * and indentation quadrupled its cost for no gain in readability, to a
     * person or to a tool.
     *
     * @param array<int, array<string, mixed>> $stack
     */
    private function renderStack(array $stack): string
    {
        $lines = [];

        foreach ($stack as $frame) {
            if (isset($frame['omitted'])) {
                $lines[] = '   … '.$frame['note'];

                continue;
            }

            $call = trim(($frame['class'] ?? '').(isset($frame['class'], $frame['function']) ? '::' : '').($frame['function'] ?? ''));

            $lines[] = sprintf(
                '%s %s:%s%s',
                ($frame['vendor'] ?? false) ? '  ' : '->',
                $frame['file'] ?? '?',
                $frame['line'] ?? '?',
                $call === '' ? '' : '  '.$call
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $diff
     */
    private function renderDiffTargets(array $diff): string
    {
        $files = (array) ($diff['files'] ?? []);

        if ($files === []) {
            return 'No application files appear in this trace.';
        }

        return implode("\n", array_map(static fn (string $file): string => '- '.$file, $files));
    }

    private function jsonSection(string $heading, mixed $payload): string
    {
        return implode("\n", [
            '## '.$heading,
            '',
            '```json',
            (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '```',
            '',
        ]);
    }

    /**
     * The offending line is marked so anyone reading plain text can see
     * which of the eleven lines actually threw.
     *
     * @param array<string, mixed>|null $excerpt
     */
    private function renderExcerpt(?array $excerpt): string
    {
        if ($excerpt === null) {
            return '// No source excerpt was captured for this error.';
        }

        $rendered = [];

        foreach ((array) ($excerpt['lines'] ?? []) as $line) {
            $marker     = ($line['offending'] ?? false) ? '>' : ' ';
            $rendered[] = $marker.' '.str_pad((string) $line['number'], 5, ' ', STR_PAD_LEFT).'  '.$line['code'];
        }

        return implode("\n", $rendered);
    }
}

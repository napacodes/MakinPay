<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Setting;
use App\Traits\FileManageTrait;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SettingService
{
    use FileManageTrait;
    use ValidatesRequests;

    /**
     * Update the settings for a given section.
     *
     * @param string  $section The section of the settings.
     * @param Request $request The request object.
     */
    public function update(string $section, Request $request): void
    {
        $rules = Setting::getValidationRules($section);

        if (! method_exists($request, 'validated')) {
            $request->merge(Setting::keptSecretInput($section, $request->all()));
        }

        $data = method_exists($request, 'validated')
            ? $request->validated()
            : $this->validate(
                $request,
                $rules,
                Setting::getValidationMessages($section),
                Setting::getValidationAttributes($section)
            );

        // Get the valid settings keys.
        $validSettings = array_keys($rules);

        // Update the settings.
        foreach ($data as $key => $val) {
            // Check if the key is a valid setting.
            if (in_array($key, $validSettings, true)) {

                // Check if the request has a file for the key.
                if ($request->hasFile($key)) {
                    // Get the old image for the key.
                    $oldImage = Setting::get($key, $section);

                    // Upload the new image and get the path.
                    $val = self::uploadImage($val, $oldImage);
                }

                // Add the setting.
                Setting::add($key, $val, Setting::getDataType($key, $section));
            }
        }

        $this->restoreRequestedImageDefaults($section, $request);

        // Section-specific post-processing — sync agent_program_enabled
        // into the FeatureManager so the existing feature.enabled middleware,
        // sidebar feature_key check, and admin Features UI all reflect the
        // master toggle without duplicating logic.
        if ($section === 'agent_settings') {
            $this->syncAgentProgramFlag();
        }

        // Applying maintenance mode has to happen here, at the moment the
        // switch is saved. Once the site is down every request short-circuits
        // in Laravel's global PreventRequestsDuringMaintenance middleware, so
        // nothing in the `web` group runs again to notice a changed secret
        // key — the administrator would be left with a recovery URL that
        // silently 503s and no way back into the site.
        if ($section === 'maintenance_mode') {
            app(MaintenanceModeService::class)->sync();
        }
    }

    /**
     * Put an image setting back to the file the application shipped with.
     *
     * The flag travels outside the validated data on purpose. An image field's
     * rule is `mimes:...`, which has no way to express "this time it is not a
     * file at all" — anything sent under the key itself would be rejected
     * before this method ever ran. So the view posts `reset_{key}` beside it,
     * and it is read straight off the request.
     *
     * A file uploaded in the same submission wins: the administrator has
     * clearly chosen a picture, whatever the flag left over from a click
     * before that says.
     */
    protected function restoreRequestedImageDefaults(string $section, Request $request): void
    {
        foreach (Setting::imageKeys($section) as $key) {
            if (! $request->boolean('reset_'.$key) || $request->hasFile($key)) {
                continue;
            }

            $default = Setting::getDefaultValueForField($key, $section);

            if (! is_string($default) || $default === '') {
                continue;
            }

            Setting::add($key, $default, Setting::getDataType($key, $section));
        }
    }

    /**
     * Mirror the `agent_program_enabled` setting onto the FeatureManager
     * `agent_program` feature row so legacy gates pick it up too.
     */
    protected function syncAgentProgramFlag(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        $enabled = (bool) (Setting::get('agent_program_enabled') ?? true);

        Feature::query()
            ->where('key', 'agent_program')
            ->update(['is_enabled' => $enabled]);

        // Flush the cached catalog so the next request sees fresh state.
        if (app()->bound(FeatureManager::class)) {
            app(FeatureManager::class)->flush();
        }
    }
}

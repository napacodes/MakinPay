<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repoint blogs.admin_id at the admins table.
     *
     * The original create migration constrained the author column to users,
     * and production kept that constraint (blogs_user_id_foreign -> users.id)
     * after the column was renamed to admin_id, so inserts with an admin id
     * failed with a foreign key violation.
     */
    public function up(): void
    {
        if (! Schema::hasTable('blogs') || $this->foreignKeyAlreadyPointsAtAdmins()) {
            return;
        }

        $this->dropStaleForeignKeys();

        if (Schema::hasColumn('blogs', 'user_id') && ! Schema::hasColumn('blogs', 'admin_id')) {
            Schema::table('blogs', function (Blueprint $table) {
                $table->renameColumn('user_id', 'admin_id');
            });
        }

        Schema::table('blogs', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_id')->nullable()->change();
        });

        DB::table('blogs')
            ->whereNotIn('admin_id', DB::table('admins')->select('id'))
            ->update(['admin_id' => null]);

        Schema::table('blogs', function (Blueprint $table) {
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        $this->dropStaleForeignKeys();

        Schema::table('blogs', function (Blueprint $table) {
            $table->foreign('admin_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    private function foreignKeyAlreadyPointsAtAdmins(): bool
    {
        foreach (Schema::getForeignKeys('blogs') as $foreignKey) {
            if ($foreignKey['columns'] === ['admin_id'] && $foreignKey['foreign_table'] === 'admins') {
                return true;
            }
        }

        return false;
    }

    private function dropStaleForeignKeys(): void
    {
        foreach (Schema::getForeignKeys('blogs') as $foreignKey) {
            if (in_array($foreignKey['columns'], [['admin_id'], ['user_id']], true)) {
                Schema::table('blogs', function (Blueprint $table) use ($foreignKey) {
                    $table->dropForeign($foreignKey['name']);
                });
            }
        }
    }
};

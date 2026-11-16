<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Certificates
{
    public function eligible(int $userId, int $courseId): bool
    {
        $modules = DB::table('modules')->where('course_id', $courseId)->where('published', true)->get();
        if ($modules->isEmpty()) {
            return false;
        }
        foreach ($modules as $module) {
            if (! DB::table('module_progress')->where('user_id', $userId)->where('module_id', $module->id)->exists()) {
                return false;
            }
            if ($module->quiz_question && ! DB::table('quiz_attempts')->where('user_id', $userId)->where('module_id', $module->id)->where('passed', true)->exists()) {
                return false;
            }
            if ($module->assignment_required && ! DB::table('assignment_submissions')->where('user_id', $userId)->where('module_id', $module->id)->where('grade', '>=', 70)->exists()) {
                return false;
            }
        }

        return true;
    }

    public function issue(int $userId, int $courseId): object
    {
        return DB::transaction(function () use ($userId, $courseId) {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $existing = DB::table('certificates')->where('user_id', $userId)->where('course_id', $courseId)->first();
            if ($existing) {
                return $existing;
            }
            abort_unless(app(Commerce::class)->canAccess($userId, $courseId) && $this->eligible($userId, $courseId), 403, 'Syarat kelulusan belum terpenuhi.');
            $course = DB::table('courses')->find($courseId);
            $id = DB::table('certificates')->insertGetId(['code' => (string) Str::uuid(), 'user_id' => $userId, 'course_id' => $courseId, 'student_name' => $user->name, 'course_title' => $course->title, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('certificates')->find($id);
        }, 3);
    }
}

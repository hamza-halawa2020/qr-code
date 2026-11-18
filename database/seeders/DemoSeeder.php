<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('Demo seeding is only allowed locally.');
        }
        if (User::where('email', 'admin@example.com')->exists()) {
            $this->command?->warn('Demo already exists; credentials were not changed.');

            return;
        }
        $branch = Branch::create(['name' => 'الفرع الرئيسي', 'timezone' => 'Africa/Cairo', 'opens_at' => '00:00', 'closes_at' => '23:59']);
        $branch->services()->create(['name' => 'خدمة عامة']);
        $lines = ['# Local demo access', '', 'Generated local credentials; do not deploy these accounts.', ''];
        foreach ([['admin', 'مدير النظام'], ['agent1', 'موظف الاستقبال الأول'], ['agent2', 'موظف الاستقبال الثاني']] as $index => [$name, $label]) {
            $password = Hash::make(12345678);
            $email = $name.'@example.com';
            $user = User::create(['name' => $label, 'email' => $email, 'password' => $password, 'role' => $index === 0 ? 'admin' : 'agent', 'branch_id' => $branch->id]);
            $lines[] = "- {$email}: `{$password}`";
            if ($index > 0) {
                $secret = Str::random(64);
                $counter = Counter::create(['branch_id' => $branch->id, 'name' => 'شباك '.$index, 'number' => $index, 'user_id' => $user->id, 'display_token_hash' => hash('sha256', $secret)]);
                $lines[] = '- Display: '.route('display.show', [$counter, $secret]);
            }
        }
        File::ensureDirectoryExists(storage_path('app/private'));
        File::put(storage_path('app/private/demo-access.md'), implode(PHP_EOL, $lines));
        $this->command?->info('Demo credentials: storage/app/private/demo-access.md');
    }
}

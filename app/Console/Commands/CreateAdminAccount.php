<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminAccount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:admin {--name=} {--email=} {--password=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new admin account';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->option('name') ?: $this->ask('Enter admin name (default: Admin)', 'Admin');
        $email = $this->option('email') ?: $this->ask('Enter admin email (default: admin@example.com)', 'admin@example.com');
        $password = $this->option('password') ?: $this->secret('Enter admin password (default: password)');

        if (!$password) {
            $password = 'password';
        }

        if (User::where('email', $email)->exists()) {
            $this->error('User with this email already exists!');
            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
        ]);

        $this->info("Admin account created successfully for {$user->email}");
        return self::SUCCESS;
    }
}

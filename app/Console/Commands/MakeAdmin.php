<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class MakeAdmin extends Command
{
    protected $signature = 'bus:make-admin {email}';

    protected $description = 'Create the admin user, or reset their password';

    public function handle(): int
    {
        $input = [
            'email' => $this->argument('email'),
            'password' => (string) $this->secret('Password (min 12 characters)'),
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        User::updateOrCreate(
            ['email' => $input['email']],
            ['name' => 'Admin', 'password' => $input['password']],
        );

        $this->info("Admin {$input['email']} is ready.");

        return self::SUCCESS;
    }
}

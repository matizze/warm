<?php

namespace Database\Factories;

use App\Enums\AttendanceDecision;
use App\Models\AttendanceCase;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceCase>
 */
class AttendanceCaseFactory extends Factory
{
    protected $model = AttendanceCase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => function () {
                $contact = Contact::create([
                    'phone_number' => (string) $this->faker->numerify('5527#########'),
                    'name' => $this->faker->name(),
                ]);

                return Conversation::create([
                    'type' => 'contact',
                    'contact_id' => $contact->id,
                ])->id;
            },
            'instance_id' => Instance::factory(),
            'message_id' => null,
            'contact_id' => null,
            'question' => $this->faker->sentence(),
            'decision' => AttendanceDecision::NoKnowledge,
            'dry_run' => true,
            'sent' => false,
        ];
    }
}

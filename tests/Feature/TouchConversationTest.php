<?php

namespace Tests\Feature;

use App\Actions\Conversations\TouchConversation;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TouchConversationTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): Conversation
    {
        $contact = Contact::create(['phone_number' => '5511777777777', 'name' => 'Ana']);

        return Conversation::create(['type' => 'contact', 'contact_id' => $contact->id]);
    }

    public function test_first_message_sets_last_message_at(): void
    {
        $conversation = $this->conversation();

        TouchConversation::run($conversation->id, now()->subHour());

        $this->assertNotNull($conversation->refresh()->last_message_at);
    }

    public function test_older_message_does_not_regress_last_message_at(): void
    {
        $conversation = $this->conversation();
        $recent = now()->subHour();

        TouchConversation::run($conversation->id, $recent);

        TouchConversation::run($conversation->id, now()->subDays(2));

        $this->assertEqualsWithDelta($recent->getTimestamp(), $conversation->refresh()->last_message_at->getTimestamp(), 1);
    }

    public function test_newer_message_updates_last_message_at(): void
    {
        $conversation = $this->conversation();

        TouchConversation::run($conversation->id, now()->subHour());
        TouchConversation::run($conversation->id, now());

        $this->assertEqualsWithDelta(now()->getTimestamp(), $conversation->refresh()->last_message_at->getTimestamp(), 1);
    }
}

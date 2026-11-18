<?php

namespace Tests\Feature;

use App\Http\Resources\SettingsPayload;
use App\Mail\ContactAcknowledgement;
use App\Mail\ContactEnquiry;
use App\Mail\TicketsIssued;
use App\Models\ContactMessage;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The contact address is one config value (config('wds.contact_email'), sales@wdsoriginals.com) used everywhere, and
 * replies to any email go there. Contact form messages are saved, emailed to it from the no-reply address with
 * Reply-To the customer, and the customer gets a short acknowledgement that repeats nothing they typed.
 */
class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private const FORM = [
        'name' => 'Jo Bloggs', 'email' => 'jo@example.com', 'topic' => 'My order', 'phone' => '07700 900123',
        'order' => 'WDS-ABC123', 'message' => "Hi,\nwhere are my tickets?", 'page' => 'https://wdscompetitions.co.uk/contact',
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Sends a mailable for real through the in-memory mailer and returns what went out. */
    private function deliver(string $to, $mailable): Email
    {
        config(['mail.default' => 'array']);
        Mail::to($to)->sendNow($mailable);
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last();

        return $sent->getOriginalMessage();
    }

    /** @param list<Address> $addresses */
    private static function emails(array $addresses): array
    {
        return array_map(fn (Address $a) => $a->toString(), $addresses);
    }

    public function test_a_message_is_saved_and_emailed_to_the_contact_address_and_acknowledged(): void
    {
        Mail::fake();
        $this->postJson('/api/contact', self::FORM)->assertOk();

        $saved = ContactMessage::sole();
        $this->assertSame(['My order', '07700 900123', 'WDS-ABC123', 'https://wdscompetitions.co.uk/contact'], [$saved->topic, $saved->phone, $saved->order_reference, $saved->page]);
        Mail::assertQueued(ContactEnquiry::class, fn (ContactEnquiry $m) => $m->hasTo('sales@wdsoriginals.com') && ! $m->hasTo('jo@example.com')
            && $m->hasFrom('noreply@wdscompetitions.co.uk') && $m->hasReplyTo('jo@example.com', 'Jo Bloggs') && $m->hasSubject('Website enquiry: My order'));
        Mail::assertQueued(ContactAcknowledgement::class, fn (ContactAcknowledgement $m) => $m->hasTo('jo@example.com') && ! $m->hasTo('sales@wdsoriginals.com'));
        Mail::assertQueuedCount(2);
    }

    public function test_the_enquiry_email_replies_to_the_customer_only_and_has_every_detail(): void
    {
        Carbon::setTestNow('2026-12-10 18:05:00');   // 18:05 UK time (GMT)
        $contact = ContactMessage::create(['name' => 'Jo Bloggs', 'email' => 'jo@example.com', 'topic' => 'My order', 'phone' => '07700 900123',
            'order_reference' => 'WDS-ABC123', 'message' => "Hi,\nwhere are my tickets?", 'page' => 'https://wdscompetitions.co.uk/contact']);

        $email = $this->deliver(config('wds.contact_email'), new ContactEnquiry($contact));

        $this->assertSame(['sales@wdsoriginals.com'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame(['noreply@wdscompetitions.co.uk'], array_map(fn ($a) => $a->getAddress(), $email->getFrom()));
        $this->assertSame(['"Jo Bloggs" <jo@example.com>'], self::emails($email->getReplyTo()), 'only the customer, not the site-wide Reply-To');
        $this->assertSame('Website enquiry: My order', $email->getSubject());
        foreach ([html_entity_decode((string) $email->getHtmlBody(), ENT_QUOTES), (string) $email->getTextBody()] as $body) {
            foreach (['Jo Bloggs', 'jo@example.com', '07700 900123', 'WDS-ABC123', 'where are my tickets?', 'https://wdscompetitions.co.uk/contact', '10 December 2026, 18:05 (UK time)'] as $part) {
                $this->assertStringContainsString($part, $body);
            }
        }

        // Without the optional fields, their rows are left out.
        $bare = ContactMessage::create(['name' => 'Al', 'email' => 'al@example.com', 'message' => 'Hello']);
        $this->assertSame(['Name', 'Email', 'Sent at'], array_keys((new ContactEnquiry($bare))->details()));
        $this->assertSame('Website enquiry: Something else', $this->deliver('sales@wdsoriginals.com', new ContactEnquiry($bare))->getSubject());
    }

    public function test_the_acknowledgement_is_short_branded_and_repeats_nothing_typed(): void
    {
        $email = $this->deliver('jo@example.com', new ContactAcknowledgement);

        $this->assertSame("We've got your message", $email->getSubject());
        $this->assertSame(['"WDS Competitions" <sales@wdsoriginals.com>'], self::emails($email->getReplyTo()), 'a reply reaches the contact address');
        foreach ([html_entity_decode((string) $email->getHtmlBody(), ENT_QUOTES), (string) $email->getTextBody()] as $body) {
            $this->assertStringContainsString(ContactAcknowledgement::LINES[0], $body);
            $this->assertStringContainsString('Questions? Email sales@wdsoriginals.com', strip_tags($body));
            $this->assertStringNotContainsString('where are my tickets', $body);
        }
    }

    public function test_every_customer_email_replies_to_the_contact_address(): void
    {
        $email = $this->deliver('jo@example.com', new \App\Mail\MailTest('array'));
        $this->assertSame(['sales@wdsoriginals.com'], array_map(fn ($a) => $a->getAddress(), $email->getReplyTo()));
    }

    public function test_the_contact_address_is_the_config_value_everywhere(): void
    {
        $this->assertSame('sales@wdsoriginals.com', SettingsPayload::get()['supportEmail']);
        // An address saved in Admin → Settings before this change no longer wins, and Admin can't set it.
        Setting::put('site', ['supportEmail' => 'competitions@wdsoriginals.com']);
        $this->assertSame('sales@wdsoriginals.com', $this->getJson('/api/settings')->json('data.supportEmail'));

        config(['wds.contact_email' => 'help@example.com']);
        $this->assertSame('help@example.com', SettingsPayload::get()['supportEmail']);

        // The live-draw handle is @wds_competitions (@wds_originals is only a social link, seeded separately).
        $this->assertSame('wds_competitions', SettingsPayload::DEFAULTS['drawsInstagram']);
        $this->assertStringContainsString('instagram.com/wds_competitions/', json_encode(SettingsPayload::DEFAULTS, JSON_UNESCAPED_SLASHES));
    }

    public function test_enquiries_go_to_the_contact_address_when_no_enquiry_address_is_set(): void
    {
        Mail::fake();
        config(['wds.contact_email' => 'shown@example.com', 'wds.enquiry_email' => null]);
        $this->postJson('/api/contact', self::FORM)->assertOk();
        Mail::assertQueued(ContactEnquiry::class, fn (ContactEnquiry $m) => $m->hasTo('shown@example.com'));
    }

    public function test_an_enquiry_address_only_changes_where_enquiries_are_delivered(): void
    {
        config(['wds.enquiry_email' => 'dev@example.com']);
        // Nothing customer-facing shows it: the site settings, the email footers and the Reply-To keep the contact address.
        $this->assertStringNotContainsString('dev@example.com', json_encode($this->getJson('/api/settings')->json()));
        $ack = $this->deliver('jo@example.com', new ContactAcknowledgement);
        $this->assertSame(['sales@wdsoriginals.com'], array_map(fn ($a) => $a->getAddress(), $ack->getReplyTo()));
        $this->assertStringNotContainsString('dev@example.com', $ack->getHtmlBody().$ack->getTextBody());

        Mail::fake();
        $this->postJson('/api/contact', self::FORM)->assertOk();
        Mail::assertQueued(ContactEnquiry::class, fn (ContactEnquiry $m) => $m->hasTo('dev@example.com') && ! $m->hasTo('sales@wdsoriginals.com')
            && $m->hasReplyTo('jo@example.com'));
        Mail::assertQueued(ContactAcknowledgement::class, fn (ContactAcknowledgement $m) => $m->hasTo('jo@example.com'));
    }

    public function test_an_unknown_topic_is_refused(): void
    {
        Mail::fake();
        $this->postJson('/api/contact', ['topic' => 'Buy now'] + self::FORM)->assertStatus(422)->assertJsonValidationErrors('topic');
        Mail::assertNothingQueued();
        $this->assertSame(0, ContactMessage::count());
    }
}

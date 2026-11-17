<?php

return [
    // The public customer contact address: site footer, contact page, FAQs, policies, email footers, and the
    // Reply-To on every email. Not editable in Admin (Settings shows it).
    'contact_email' => env('CONTACT_EMAIL', 'sales@wdsoriginals.com'),

    // Where contact form enquiry emails are delivered (their To only; never shown to customers). Unset: the
    // contact address. Staging sets it to a developer so test enquiries don't reach sales@.
    'enquiry_email' => env('ENQUIRY_EMAIL') ?: null,

    // Contact form enquiries are sent from this address (Reply-To is the customer).
    'noreply_address' => env('NOREPLY_ADDRESS', 'noreply@wdscompetitions.co.uk'),

    // The real production API address(es). Admin shows an amber STAGING badge unless APP_URL is one of these.
    // The on-forge address is production's until api.wdscompetitions.co.uk is live (forge/README.md).
    'production_app_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('PRODUCTION_APP_URLS',
        'https://api.wdscompetitions.co.uk,https://wds-competitions-api.on-forge.com'))))),

    // Site footer: social links, the ICO registration line (shown only when set) and "Powered by RaffleLabs".
    'instagram' => ['handle' => 'wds_originals', 'url' => 'https://instagram.com/wds_originals'],
    'youtube' => ['name' => 'WDS Originals', 'url' => 'https://youtube.com/@wdsoriginals'],
    'ico_number' => env('ICO_NUMBER') ?: null,
    'powered_by' => (bool) env('SHOW_POWERED_BY', true),

    // Launch emails sent a second (LaunchAlerts). Resend's default limit is 2 requests a second per team.
    'launch_alerts_per_second' => (int) env('LAUNCH_ALERTS_PER_SECOND', 2),

    // Topics on the contact form ("Website enquiry: <topic>").
    'contact_topics' => ['A competition', 'My order', 'Free postal entry', 'Draws and winners', 'My account', 'Something else'],
];

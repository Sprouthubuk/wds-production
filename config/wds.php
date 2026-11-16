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

    // Topics on the contact form ("Website enquiry: <topic>").
    'contact_topics' => ['A competition', 'My order', 'Free postal entry', 'Draws and winners', 'My account', 'Something else'],
];

<?php

return [
    'dir' => 'ltr',
    'launcher' => 'Report an issue',
    'form' => ['title' => 'Title', 'description' => 'Describe the issue', 'submit' => 'Send'],
    'type' => ['bug' => 'Bug', 'feature' => 'Feature request', 'question' => 'Question', 'other' => 'Other'],
    'status' => ['new' => 'New', 'in_review' => 'In review', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed', 'declined' => 'Declined'],
    'priority' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'],
    'mail' => [
        'new_subject' => 'New feedback: :title', 'new_line' => 'A new report was submitted.',
        'reply_subject' => 'New reply on your feedback',
        'resolved_subject' => 'Resolved: :title', 'resolved_line' => 'Your report was resolved.',
    ],
    'board' => ['my_reports' => 'My reports', 'reply' => 'Reply', 'internal_note' => 'Internal note'],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | E-Call Attachments
    |--------------------------------------------------------------------------
    |
    | Photos and documents a Requestor attaches when filing an E-Call. The PRD
    | gives no size limit, so the default is 10 MB (10240 KB) per file and at
    | most 10 files per E-Call. Files go to a private disk.
    |
    */

    'e_call_attachments' => [
        'disk' => env('PSS_E_CALL_ATTACHMENTS_DISK', 'local'),
        'max_kilobytes' => (int) env('PSS_E_CALL_ATTACHMENT_MAX_KB', 10240),
        'max_files' => (int) env('PSS_E_CALL_ATTACHMENT_MAX_FILES', 10),
        'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
    ],

];

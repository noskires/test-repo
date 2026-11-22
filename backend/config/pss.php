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

    /*
    |--------------------------------------------------------------------------
    | Bill of Quantities
    |--------------------------------------------------------------------------
    |
    | The PRD sets no quantity precision. BOQ quantities accept up to 3 decimal
    | places by default (0 to 3 allowed; storage keeps thousandths). Line totals
    | are stored exactly, never rounded. The BOQ total is stored both exactly and
    | rounded half-up to the centavo. The FSR file follows the E-Call attachment
    | rules above.
    |
    */

    'boq' => [
        'quantity_decimal_places' => (int) env('PSS_BOQ_QUANTITY_DECIMAL_PLACES', 3),
        'max_lines' => (int) env('PSS_BOQ_MAX_LINES', 200),
    ],

];

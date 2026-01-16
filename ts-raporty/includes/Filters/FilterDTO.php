<?php
namespace TSR\Filters;

if (!defined('ABSPATH')) { exit; }

final class FilterDTO {
    public $date_from;      // Y-m-d or ''
    public $date_to;        // Y-m-d or ''
    public $values_mode;    // 'gross'|'net'
    public $invoice_mode;   // 'all'|'with'|'without'
    public $statuses;       // array of wc-* (without 'wc-' in WC API is acceptable)
    public $categories;     // array of term_ids (int)
    public $payment_methods;// array of gateway ids
    public $product_ids;    // array of ints
    public $event_date_from; // Y-m-d (passes)
    public $event_date_to;   // Y-m-d (passes)
    public $event_date_mode; // 'all'|'with'|'without' (passes)
    public string $origin_mode = 'all'; // NOWE: 'all'|'web'|'admin'
    public array $buildings = []; // Nowe pole
    public array $product_names = []; // Nowe pole dla multiselecta nazw

    public function __construct(array $args = []) {
        $this->date_from = (string)($args['date_from'] ?? '');
        $this->date_to = (string)($args['date_to'] ?? '');
        $this->values_mode = (string)($args['values_mode'] ?? 'gross');
        $this->invoice_mode = (string)($args['invoice_mode'] ?? 'all');
        $this->statuses = (array)($args['statuses'] ?? []);
        $this->categories = (array)($args['categories'] ?? []);
        $this->payment_methods = (array)($args['payment_methods'] ?? []);
        $this->product_ids = (array)($args['product_ids'] ?? []);
        $this->event_date_from = (string)($args['event_date_from'] ?? '');
        $this->event_date_to = (string)($args['event_date_to'] ?? '');
        $this->event_date_mode = (string)($args['event_date_mode'] ?? 'all');
        $this->origin_mode = (string)($args['origin_mode'] ?? 'all');
        $this->buildings = (array)($args['buildings'] ?? []);
        $this->product_names = (array)($args['product_names'] ?? []);
    }
}

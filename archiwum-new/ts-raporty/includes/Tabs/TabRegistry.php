<?php
namespace TSR\Tabs;

use TSR\Tabs\TransactionsTab;
use TSR\Tabs\SalesSummaryTab;
use TSR\Tabs\MealsTab;
use TSR\Tabs\PassesTab;
use TSR\Tabs\SettingsTab;

if (!defined('ABSPATH')) { exit; }

final class TabRegistry {
    private static $instance = null;
    private $tabs = [];

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register(new TransactionsTab());
        $this->register(new SalesSummaryTab());
        $this->register(new MealsTab());
        $this->register(new PassesTab());
        $this->register(new SettingsTab());
    }

    private function register(TabInterface $tab): void {
        $this->tabs[$tab->get_key()] = $tab;
    }

    public function get(string $key): ?TabInterface {
        return $this->tabs[$key] ?? null;
    }

    /** @return array<string, TabInterface> */
    public function all(): array {
        return $this->tabs;
    }
}

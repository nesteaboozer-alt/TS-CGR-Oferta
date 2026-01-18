<?php
class TSKF_Engine {
    static function is_period($t){ return $t->ticket_type === 'period'; }

    static function can_use($t){
        if ($t->status === 'void') return false;
        $now = current_time('timestamp');
        if ($t->period_expires_at && $now > strtotime($t->period_expires_at)) return false;
        if (self::is_period($t)) return true;
        return (int)$t->entries_left > 0;
    }

    static function consume(&$t, $user_id){
        if (! self::can_use($t)) {
            return new WP_Error('cannot_use','Nie można użyć kodu');
        }

        $now_mysql = TSKF_Helpers::now_mysql();

        if (self::is_period($t) && empty($t->period_started_at) && (int)$t->duration_days > 0) {
            $days = max(1, (int)$t->duration_days);
            $ts   = current_time('timestamp');
            if ($days > 1) {
                $ts = strtotime('+'.($days-1).' days', $ts);
            }
            $expires = date('Y-m-d 23:59:59', $ts);

            $t->period_started_at = $now_mysql;
            $t->period_expires_at = $expires;
            $t->status            = 'activated';
        }

        if (! self::is_period($t)) {
            $t->entries_left = max(0, (int)$t->entries_left - 1);
            if ((int)$t->entries_left === 0) {
                $t->status = 'exhausted';
            }
        }

        TSKF_Tickets::update($t->id, [
            'entries_left'      => $t->entries_left,
            'period_started_at' => $t->period_started_at,
            'period_expires_at' => $t->period_expires_at,
            'status'            => $t->status,
            'last_checked_at'   => $now_mysql,
            'last_checked_by'   => $user_id,
            'updated_at'        => $now_mysql,
        ]);

        return $t;
    }
}

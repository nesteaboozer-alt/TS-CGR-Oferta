<?php /** @var WC_Order $order */ $order = isset($order) ? $order : wc_get_order(get_the_ID()); ?>
<?php $groups = isset($groups_for_template) ? $groups_for_template : []; ?>
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; line-height:1.5; color:#111;">
  <div style="max-width:640px;margin:0 auto;padding:24px 16px;">
    <h2 style="margin:0 0 8px; font-size:22px;">Twoje karnety</h2>
    <?php if(isset($order) && $order): ?>
      <p style="margin:0 0 16px;">Dziękujemy za zakup. Zamówienie <strong>#<?php echo esc_html($order->get_order_number()); ?></strong></p>
    <?php endif; ?>

    <?php foreach($groups as $g): ?>
      <div style="margin:14px 0 6px; font-weight:600;">
        <?php echo esc_html($g['product']); ?>
      </div>

      <?php foreach($g['codes'] as $code): ?>
        <div style="background:#0b0b0b;color:#fff;border-radius:10px;padding:12px 14px;margin:6px 0; font-family: 'SF Mono', ui-monospace, Menlo, Consolas, 'Courier New', monospace; letter-spacing:1px; font-size:16px; text-align:center;">
          <?php echo esc_html($code); ?>
        </div>
      <?php endforeach; ?>

      <?php if ( !empty($g['is_zabieg']) ): ?>
        <div style="margin-top:8px; font-size:14px; color:#444;">
          <p style="margin:0 0 6px;">
            To Voucher, aby zarezerwować termin skontaktuj się ze SPA pod numerem:
            <strong>+74 884 34 09</strong> lub adresem e-mail:
            <a href="mailto:spa@apartamenty.czarnagora.pl">spa@apartamenty.czarnagora.pl</a>.
          </p>
          <p style="margin:0 0 6px;">
            Prosimy podać kod Vouchera, imię oraz nazwisko i wybraną datę.
          </p>
          <p style="margin:0;">
            Voucher jest ważny 30 dni od daty zakupu.
          </p>
        </div>
      <?php else: ?>
        <div style="margin-top:8px; font-size:14px; color:#444;">
          <strong>Jak skorzystać?</strong>
          <ol style="margin:6px 0 0 18px; padding:0;">
            <li>Przy recepcji podaj kasjerowi kod z tej wiadomości.</li>
            <li>Kasjer potwierdzi użycie w systemie i wpuści Cię na obiekt.</li>
            <li>W razie problemów skontaktuj się pod tel: +74 884 34 09</li>
          </ol>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

  </div>
</div>

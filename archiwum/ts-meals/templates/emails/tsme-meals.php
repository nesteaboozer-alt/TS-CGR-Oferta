<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var WC_Order $order */
/** @var array     $meal_items */
/** @var string    $email_heading */
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo esc_html( $email_heading ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<div style="max-width:640px;margin:0 auto;padding:24px 16px;">

    <h2 style="margin:0 0 16px;font-size:20px;color:#111827;">
        <?php echo esc_html( $email_heading ); ?>
    </h2>

    <p style="margin:0 0 12px;color:#374151;font-size:14px;line-height:1.5;">
        <?php esc_html_e( 'Twoje posiłki zostały już odnotowane przez zespół Gastronomii i Twój pobyt jest na liście obecności przy wejściu do restauracji.', 'ts-hotel-meals' ); ?>
    </p>

    <p style="margin:0 0 16px;color:#374151;font-size:14px;line-height:1.5;">
        <?php esc_html_e( 'Co jeżeli nie będzie mnie na liście obecności?', 'ts-hotel-meals' ); ?>
        <?php esc_html_e( 'Nie zakładamy takiego scenariusza, ale na wszystko jesteśmy przygotowani! W razie problemów każdy z poniższych posiłków ma wygenerowany kod awaryjny, który obsługa restauracji może wykorzystać do weryfikacji na wejściu.', 'ts-hotel-meals' ); ?>
    </p>

    <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;background:#ffffff;border-radius:8px;overflow:hidden;font-size:13px;">
        <thead>
            <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                <th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Produkt', 'ts-hotel-meals' ); ?></th>
                <th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Obiekt / pokój', 'ts-hotel-meals' ); ?></th>
                <th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Pobyt', 'ts-hotel-meals' ); ?></th>
                <th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Osoby', 'ts-hotel-meals' ); ?></th>
                <th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Kod awaryjny', 'ts-hotel-meals' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $meal_items as $mi ) : ?>
            <tr style="border-bottom:1px solid #e5e7eb;">
                <td style="padding:8px 10px; vertical-align:top;">
                    <strong><?php echo esc_html( $mi['name'] ); ?></strong>
                </td>
                <td style="padding:8px 10px; vertical-align:top;">
                    <?php echo esc_html( $mi['object'] ); ?><br/>
                    <span style="color:#6b7280;"><?php printf( esc_html__( 'Pokój %s', 'ts-hotel-meals' ), esc_html( $mi['room'] ) ); ?></span>
                </td>
                <td style="padding:8px 10px; vertical-align:top;">
                    <?php
                    $from = $mi['stay_from'] ?: '-';
                    $to   = $mi['stay_to'] ?: '-';
                    echo esc_html( $from . ' – ' . $to );
                    ?>
                </td>
                <td style="padding:8px 10px; vertical-align:top;">
                    <?php
                    printf(
                        esc_html__( 'Dorośli: %1$s, Dzieci: %2$s', 'ts-hotel-meals' ),
                        $mi['adults'] !== '' ? $mi['adults'] : '0',
                        $mi['children'] !== '' ? $mi['children'] : '0'
                    );
                    ?>
                </td>
                <td style="padding:8px 10px; vertical-align:top;">
                    <strong><?php echo esc_html( $mi['code'] ); ?></strong>
                </td>
            </tr>

            <?php if ( ! empty( $mi['messages'] ) && is_array( $mi['messages'] ) ) : ?>
                <tr>
                    <td colspan="5" style="padding:0 10px 10px 10px; border-bottom:1px solid #e5e7eb; background-color:#ffffff;">
                        <div style="margin-top:4px;">
                            <?php foreach ( $mi['messages'] as $msg ) : ?>
                                <div style="display:block; width:100%; background-color:#eff6ff; color:#1e40af; border:1px solid #dbeafe; padding:8px 12px; border-radius:6px; font-size:12px; margin-bottom:4px; box-sizing:border-box;">
                                    <?php echo esc_html( $msg ); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>

        <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin:18px 0 0;color:#6b7280;font-size:12px;">
        <?php esc_html_e( 'Jeśli masz pytania dotyczące posiłków lub rezerwacji, skontaktuj się z recepcją obiektu.', 'ts-hotel-meals' ); ?>
    </p>

    <p style="margin:8px 0 0;color:#6b7280;font-size:12px;">
        <?php echo esc_html( get_bloginfo( 'name' ) ); ?> <strong>https://uslugi.czarnagora.pl</strong>
    </p>

</div>
</body>
</html>
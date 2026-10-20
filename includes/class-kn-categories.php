<?php
/** Administrator-managed categories with their own colours; text colours are derived for WCAG AA contrast. */
if (!defined('ABSPATH')) { exit; }

final class KN_Categories {
    const TAXONOMIES = array('kn_topic' => 'Themen (für Termine)', 'kn_institution_type' => 'Einrichtungsarten');
    // Design 2 "Farbstimmungen": the seven theme pastels first, further pastels for new categories.
    const PASTELS = array('#ffdfcb', '#cdf1e1', '#cbe5fb', '#fbd3e7', '#dfd6fa', '#fbf0b5', '#c4eeec', '#e4f2c7', '#f9d9c9', '#d6def8', '#f2d7ef', '#d3ecd6');
    const DEFAULTS = array('Vortrag' => '#ffdfcb', 'Gesundheit' => '#cdf1e1', 'Bildung' => '#cbe5fb', 'Kunst und Kultur' => '#fbd3e7', 'Anthroposophie' => '#dfd6fa', 'Religion' => '#fbf0b5', 'Esoterik und Spiritualität' => '#c4eeec', 'Schule' => '#cbe5fb');

    public function __construct() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);
        add_action('admin_post_kn_categories', array(__CLASS__, 'save'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('delete_term', array(__CLASS__, 'deleted'), 10, 3);
    }

    /** Ordered terms of one taxonomy, each with ->kn_bg and ->kn_ink. */
    public static function terms($taxonomy) {
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
        if (is_wp_error($terms) || !$terms) { return array(); }
        usort($terms, function ($a, $b) {
            $oa = (int) get_term_meta($a->term_id, 'kn_order', true) ?: 999; $ob = (int) get_term_meta($b->term_id, 'kn_order', true) ?: 999;
            return $oa === $ob ? strnatcasecmp($a->name, $b->name) : $oa <=> $ob;
        });
        $used = array();
        foreach ($terms as $term) { $stored = sanitize_hex_color((string) get_term_meta($term->term_id, 'kn_color', true)); $term->kn_bg = $stored ?: (self::DEFAULTS[$term->name] ?? ''); if ($term->kn_bg) { $used[] = $term->kn_bg; } }
        foreach ($terms as $term) {
            if (!$term->kn_bg) { $free = array_values(array_diff(self::PASTELS, $used)); $term->kn_bg = $free ? $free[0] : self::PASTELS[count($used) % count(self::PASTELS)]; $used[] = $term->kn_bg; }
            $term->kn_ink = self::ink_for($term->kn_bg);
        }
        return $terms;
    }

    /** Keeps the familiar order of the default categories and stores their colours, so renaming keeps the colour. */
    public static function ensure_defaults() {
        foreach (array('kn_topic' => KN_Core::TOPICS, 'kn_institution_type' => KN_Core::ORG_TYPES) as $taxonomy => $defaults) {
            $terms = self::terms($taxonomy); $next = count($defaults) + 1;
            foreach ($terms as $term) {
                if (!(int) get_term_meta($term->term_id, 'kn_order', true)) { $index = array_search($term->name, $defaults, true); update_term_meta($term->term_id, 'kn_order', $index === false ? $next++ : $index + 1); }
                if (!sanitize_hex_color((string) get_term_meta($term->term_id, 'kn_color', true))) { update_term_meta($term->term_id, 'kn_color', $term->kn_bg); }
            }
        }
    }

    public static function term_colors($term_id) {
        foreach (array_keys(self::TAXONOMIES) as $taxonomy) { foreach (self::terms($taxonomy) as $term) { if ((int) $term->term_id === (int) $term_id) { return array($term->kn_bg, $term->kn_ink); } } }
        return array('#eeeafa', '#26233a');
    }

    /** CSS custom properties per category for badges, chips, cards and calendar entries. */
    public static function css() {
        $css = '';
        foreach (array_keys(self::TAXONOMIES) as $taxonomy) {
            foreach (self::terms($taxonomy) as $term) { $id = (int) $term->term_id; $css .= '.kn-portal .kn-term-' . $id . ',.kn-portal [data-term="' . $id . '"]{--kn-badge-bg:' . $term->kn_bg . ';--kn-badge-ink:' . $term->kn_ink . '}'; }
        }
        return $css;
    }

    private static function rgb($hex) { $hex = ltrim($hex, '#'); if (strlen($hex) === 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; } return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))); }
    private static function luminance($hex) { $c = array_map(function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }, self::rgb($hex)); return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]; }
    public static function contrast($a, $b) { $la = self::luminance($a); $lb = self::luminance($b); return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05); }
    public static function is_light($hex) { return self::luminance($hex) > 0.55; }

    /** Dark text in the hue of the background (≥ 6.5:1 preferred, ≥ 4.5:1 guaranteed). */
    public static function ink_for($bg) {
        list($r, $g, $b) = array_map(function ($v) { return $v / 255; }, self::rgb($bg));
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2; $d = $max - $min; $h = 0; $s = 0;
        if ($d > 0) { $s = $d / (1 - abs(2 * $l - 1)); if ($max === $r) { $h = fmod(($g - $b) / $d, 6); } elseif ($max === $g) { $h = ($b - $r) / $d + 2; } else { $h = ($r - $g) / $d + 4; } $h = fmod($h * 60 + 360, 360); }
        $s = $s < 0.05 ? 0 : max(0.25, min($s, 0.8)); $fallback = '';
        for ($step = 0; $step <= 45; $step++) {
            $candidate = self::hsl(min($l, 0.45) - $step * 0.01, $h, $s); $ratio = self::contrast($candidate, $bg);
            if ($ratio >= 6.5) { return $candidate; }
            if ($ratio >= 4.5 && !$fallback) { $fallback = $candidate; }
        }
        if ($fallback) { return $fallback; }
        for ($step = 0; $step <= 45; $step++) { $candidate = self::hsl(max($l, 0.55) + $step * 0.01, $h, $s); if (self::contrast($candidate, $bg) >= 4.5) { return $candidate; } }
        return self::contrast('#000000', $bg) >= self::contrast('#ffffff', $bg) ? '#000000' : '#ffffff';
    }

    private static function hsl($l, $h, $s) {
        $l = max(0, min(1, $l)); $c = (1 - abs(2 * $l - 1)) * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $l - $c / 2;
        $parts = $h < 60 ? array($c, $x, 0) : ($h < 120 ? array($x, $c, 0) : ($h < 180 ? array(0, $c, $x) : ($h < 240 ? array(0, $x, $c) : ($h < 300 ? array($x, 0, $c) : array($c, 0, $x)))));
        return '#' . implode('', array_map(function ($v) use ($m) { return sprintf('%02x', (int) round(($v + $m) * 255)); }, $parts));
    }

    public static function menu() {
        add_submenu_page('konstanth', 'Kategorien & Farben', 'Kategorien & Farben', 'manage_options', 'kn-categories', array(__CLASS__, 'render'));
    }

    public static function assets($hook) {
        if (strpos((string) $hook, 'kn-categories') === false) { return; }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('kn-categories', KN_URL . 'assets/categories.js', array('wp-color-picker'), KN_VERSION, true);
    }

    private static function subscriptions($term_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}kn_subscribers WHERE org_id=0 AND topic=%s AND status='active'", 't' . (int) $term_id));
    }

    public static function render() {
        if (!current_user_can('manage_options')) { wp_die('Keine Berechtigung.', '', array('response' => 403)); }
        $message = get_transient('kn_categories_notice_' . get_current_user_id());
        echo '<div class="wrap kn-admin kn-categories"><h1>Kategorien & Farben</h1>';
        if ($message) { echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>'; delete_transient('kn_categories_notice_' . get_current_user_id()); }
        echo '<p class="kn-admin-intro">Themen ordnen Termine (höchstens drei pro Termin) und lassen sich als Rundmail abonnieren. Einrichtungsarten beschreiben die Einrichtungen. Die Farbe erscheint in Filtern, Karten, Kalender, Anmeldeformular und Rundmail. Die Schriftfarbe berechnet konstanth automatisch so, dass sie gut lesbar bleibt (mindestens 4,5:1).</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="kn-categories-form"><input type="hidden" name="action" value="kn_categories">';
        wp_nonce_field('kn_categories');
        foreach (self::TAXONOMIES as $taxonomy => $label) {
            echo '<h2>' . esc_html($label) . '</h2><table class="widefat striped kn-category-table"><thead><tr><th scope="col">Reihenfolge</th><th scope="col">Name</th><th scope="col">Farbe</th><th scope="col">Vorschau</th><th scope="col">Verwendung</th><th scope="col">Löschen</th></tr></thead><tbody>';
            foreach (self::terms($taxonomy) as $position => $term) {
                $id = (int) $term->term_id; $subs = $taxonomy === 'kn_topic' ? self::subscriptions($id) : 0;
                $usage = sprintf('%d %s', (int) $term->count, $taxonomy === 'kn_topic' ? ((int) $term->count === 1 ? 'Termin' : 'Termine') : ((int) $term->count === 1 ? 'Einrichtung' : 'Einrichtungen')) . ($taxonomy === 'kn_topic' ? ' · ' . $subs . ' ' . ($subs === 1 ? 'Abo' : 'Abos') : '');
                $name = 'terms[' . $id . ']';
                echo '<tr><td><input type="number" min="1" class="small-text" name="' . esc_attr($name . '[order]') . '" value="' . (int) ($position + 1) . '" aria-label="' . esc_attr('Reihenfolge von ' . $term->name) . '"></td>';
                echo '<td><input type="text" class="regular-text" required maxlength="60" name="' . esc_attr($name . '[name]') . '" value="' . esc_attr($term->name) . '" aria-label="Name"></td>';
                echo '<td><input type="text" class="kn-color" data-preview="kn-preview-' . $id . '" name="' . esc_attr($name . '[color]') . '" value="' . esc_attr($term->kn_bg) . '" aria-label="' . esc_attr('Farbe von ' . $term->name) . '"></td>';
                echo '<td><span class="kn-category-preview" id="kn-preview-' . $id . '" style="background:' . esc_attr($term->kn_bg) . ';color:' . esc_attr($term->kn_ink) . '">' . esc_html($term->name) . '</span><span class="kn-contrast description"></span></td>';
                echo '<td>' . esc_html($usage) . '</td><td><label><input type="checkbox" class="kn-delete" name="' . esc_attr($name . '[delete]') . '" value="1" data-usage="' . esc_attr($usage) . '"> löschen</label></td></tr>';
            }
            echo '<tr class="kn-new-category"><td>neu</td><td><input type="text" class="regular-text" maxlength="60" name="' . esc_attr('new[' . $taxonomy . '][name]') . '" placeholder="Neue Kategorie" aria-label="Name der neuen Kategorie"></td><td><input type="text" class="kn-color" data-preview="kn-preview-new-' . esc_attr($taxonomy) . '" name="' . esc_attr('new[' . $taxonomy . '][color]') . '" value="" aria-label="Farbe der neuen Kategorie"></td><td><span class="kn-category-preview" id="kn-preview-new-' . esc_attr($taxonomy) . '">Vorschau</span><span class="kn-contrast description"></span></td><td colspan="2" class="description">Ohne Farbwahl erhält die neue Kategorie den nächsten freien Pastellton.</td></tr>';
            echo '</tbody></table>';
        }
        submit_button('Änderungen speichern');
        echo '</form><p class="description">Wird eine Kategorie gelöscht, verschwindet sie aus allen Terminen bzw. Einrichtungen. Themen-Abos dieser Kategorie werden beendet; die Abonnenten erhalten dazu keine Rundmails mehr. Ein Termin braucht weiterhin mindestens ein Thema, bevor er veröffentlicht werden kann.</p></div>';
    }

    public static function save() {
        if (!current_user_can('manage_options')) { wp_die('Keine Berechtigung.', '', array('response' => 403)); }
        check_admin_referer('kn_categories');
        $changes = 0; $hints = array();
        $rows = isset($_POST['terms']) && is_array($_POST['terms']) ? wp_unslash($_POST['terms']) : array();
        foreach ($rows as $id => $row) {
            $term = get_term(absint($id)); if (!$term || is_wp_error($term) || !isset(self::TAXONOMIES[$term->taxonomy]) || !is_array($row)) { continue; }
            if (!empty($row['delete'])) { wp_delete_term($term->term_id, $term->taxonomy); $changes++; continue; }
            $name = sanitize_text_field((string) ($row['name'] ?? '')); if ($name !== '' && $name !== $term->name) { $updated = wp_update_term($term->term_id, $term->taxonomy, array('name' => $name)); if (!is_wp_error($updated)) { $changes++; } else { $hints[] = $updated->get_error_message(); } }
            $color = sanitize_hex_color((string) ($row['color'] ?? '')); if ($color) { update_term_meta($term->term_id, 'kn_color', $color); if (!self::is_light($color)) { $hints[] = '„' . $name . '“ hat eine dunkle Farbe. Für das Pastell-Design empfehlen wir hellere Töne; die Schrift bleibt trotzdem lesbar.'; } }
            update_term_meta($term->term_id, 'kn_order', max(1, absint($row['order'] ?? 1)));
        }
        $new = isset($_POST['new']) && is_array($_POST['new']) ? wp_unslash($_POST['new']) : array();
        foreach ($new as $taxonomy => $row) {
            $taxonomy = sanitize_key($taxonomy); $name = sanitize_text_field((string) ($row['name'] ?? ''));
            if (!isset(self::TAXONOMIES[$taxonomy]) || $name === '') { continue; }
            $inserted = wp_insert_term($name, $taxonomy);
            if (is_wp_error($inserted)) { $hints[] = $inserted->get_error_message(); continue; }
            $color = sanitize_hex_color((string) ($row['color'] ?? '')); if ($color) { update_term_meta($inserted['term_id'], 'kn_color', $color); }
            update_term_meta($inserted['term_id'], 'kn_order', count(get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false))));
            $changes++;
        }
        set_transient('kn_categories_notice_' . get_current_user_id(), trim(($changes ? 'Gespeichert.' : 'Keine Änderungen.') . ' ' . implode(' ', array_unique($hints))), 120);
        wp_safe_redirect(admin_url('admin.php?page=kn-categories')); exit;
    }

    /** Topic subscriptions end with their category; nobody receives mail for a topic that no longer exists. */
    public static function deleted($term_id, $tt_id, $taxonomy) {
        if ($taxonomy !== 'kn_topic') { return; }
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}kn_subscribers SET status='unsubscribed',updated_at=%d WHERE org_id=0 AND topic=%s AND status IN ('active','pending')", time(), 't' . (int) $term_id));
    }
}

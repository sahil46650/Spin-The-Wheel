<?php
/**
 * Plugin Name: Wheel CSV Importer (Upload UI)
 * Description: Import/update 'wheel' custom post type from CSV. Supports ACF repeater, Yoast meta, and wheel-category taxonomy. Handles special characters and smart category matching.
 * Version: 1.4.0
 * Author: ChatGPT (modified)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wheel_CSV_Importer_UI {
    const CPT = 'wheel';
    const TAXONOMY = 'wheels';

    const ACF_REPEATER_KEY = 'wheel_options';
    const ACF_LABEL_KEY    = 'add_options';
    const ACF_COLOR_KEY    = 'add_color';
    const DEFAULT_MAX      = 15;

    const DEFAULT_COLORS = ['#eae56f','#89f26e','#7de6ef','#e7706f','#c295f2','#f89b29','#f89b59'];

    // List of current existing categories (can also get dynamically via get_terms)
    private $existing_categories = [
        'Sports', 'Fantasy', 'Fashion & Lifestyle', 'Random', 'Travel & World', 'Social', 'Other', 
        'Food & Drink', 'Entertainment', 'Holidays & Occasions', 'Party & Games', 'Arts & Crafts', 
        'Video Games', 'Board Games', 'Music', 'Animals & Nature', 'Fashion', 'Education', 
        'Health & Wellness', 'Gaming', 'All Wheels', 'Chance & Fortune', 'Comedy & Fun', 
        'Lifestyle', 'Tools', 'Decision Making', 'Technology'
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_wheel_csv_import', [$this, 'handle_upload']);
        add_action('admin_notices', [$this, 'maybe_show_notice']);
    }

    public function add_menu() {
        add_management_page(
            'Wheel CSV Importer',
            'Wheel CSV Importer',
            'manage_options',
            'wheel-csv-importer',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Wheel CSV Importer</h1>
            <p>Upload your CSV to import/update <code><?php echo esc_html(self::CPT); ?></code> posts and ACF repeater <code><?php echo esc_html(self::ACF_REPEATER_KEY); ?></code>.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="wheel_csv_import">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="csv_file">CSV File</label></th>
                        <td>
                            <input type="file" name="csv_file" id="csv_file" accept=".csv" required />
                            <p class="description">
                                Expected headers (case-insensitive): <code>title</code>, <code>options</code>, optionally <code>options_count</code>, <code>category</code>, <code>meta_title</code>, <code>meta_description</code>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="options_delim">Options delimiter</label></th>
                        <td>
                            <input type="text" name="options_delim" id="options_delim" value="," style="width:80px" />
                            <p class="description">Used to split the <code>options</code> column into individual items (default <code>,</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_options">Max options per wheel</label></th>
                        <td>
                            <input type="number" min="1" name="max_options" id="max_options" value="<?php echo esc_attr(self::DEFAULT_MAX); ?>" style="width:80px" />
                            <p class="description">Extra options beyond this number are ignored.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">When a post with the same title exists</th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="on_duplicate" value="update" checked /> Update it</label><br/>
                                <label><input type="radio" name="on_duplicate" value="skip" /> Skip row</label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Dry run</th>
                        <td>
                            <label><input type="checkbox" name="dry_run" value="1" /> Parse and report only (no writes)</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Import CSV'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_upload() {
        if (!current_user_can('manage_options')) wp_die('Not allowed');
        if (empty($_FILES['csv_file']['tmp_name'])) wp_die('No file uploaded');

        $delim = isset($_POST['options_delim']) && $_POST['options_delim'] !== '' ? wp_unslash($_POST['options_delim']) : ',';
        $delim = (string)$delim;

        $max_options = isset($_POST['max_options']) ? intval($_POST['max_options']) : self::DEFAULT_MAX;
        if ($max_options <= 0) $max_options = self::DEFAULT_MAX;

        $on_duplicate = isset($_POST['on_duplicate']) && $_POST['on_duplicate'] === 'skip' ? 'skip' : 'update';
        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] == '1';

        $file_path = $_FILES['csv_file']['tmp_name'];

        // --- Normalize encoding to UTF-8 ---
        $contents = file_get_contents($file_path);
        $encoding = mb_detect_encoding($contents, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding !== 'UTF-8') {
            $contents = mb_convert_encoding($contents, 'UTF-8', $encoding ?: 'Windows-1252');
        }
        $tmp_path = wp_tempnam($file_path);
        file_put_contents($tmp_path, $contents);

        $fh = fopen($tmp_path, 'r');
        if (!$fh) wp_die('Could not open uploaded CSV file.');

        $header_row = fgetcsv($fh);
        if (!$header_row) { fclose($fh); wp_die('CSV header row missing or file empty.'); }
        $headers = array_map(function($h){ return strtolower(trim($h)); }, $header_row);

        $rows_processed = $rows_skipped = $rows_created = $rows_updated = 0;
        $errors = [];

        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < count($headers)) $row = array_pad($row, count($headers), '');
            $data = array_combine($headers, $row);
            if (!$data) { $errors[] = "Malformed CSV row at line " . ($rows_processed + 2); continue; }

            $title_raw = trim($data['title'] ?? '');
            $options_raw = trim($data['options'] ?? '');
            $category_raw = trim($data['category'] ?? '');
            $meta_title = trim($data['meta_title'] ?? '');
            $meta_description = trim($data['meta_description'] ?? '');

            if ($title_raw === '') { $rows_skipped++; continue; }
            $rows_processed++;

            $title = wp_strip_all_tags($title_raw);
            $slug = sanitize_title($title);
            $existing_post = $this->get_post_by_title_and_cpt($title, self::CPT);
            if ($existing_post && $on_duplicate === 'skip') { $rows_skipped++; continue; }

            $post_data = [
                'post_title' => $title,
                'post_name' => $slug,
                'post_status' => 'publish',
                'post_type' => self::CPT,
                'post_excerpt' => $meta_description,
            ];
            if ($existing_post) $post_data['ID'] = $existing_post->ID;

            if ($dry_run) {
                if ($existing_post) $rows_updated++; else $rows_created++;
                continue;
            }

            $post_id = $existing_post ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) { $errors[] = sprintf('WP error for row "%s": %s', $title, $post_id->get_error_message()); continue; }
            if ($existing_post) $rows_updated++; else $rows_created++;

            // --- Smart category assignment ---
            if ($category_raw !== '') {
                $assigned_terms = [];
                $category_raw = trim($category_raw);

                // Split the CSV category into words (non-alphanumeric split)
                $words = preg_split('/[^a-z0-9]+/i', $category_raw, -1, PREG_SPLIT_NO_EMPTY);
                $words = array_map('strtolower', $words);

                // Get all existing terms
                $all_terms = get_terms([
                    'taxonomy' => self::TAXONOMY,
                    'hide_empty' => false,
                ]);

                $existing_term_names = [];
                if (!is_wp_error($all_terms)) {
                    foreach ($all_terms as $term) {
                        $existing_term_names[$term->term_id] = strtolower($term->name);
                    }
                }

                // Match all words against existing categories
                foreach ($existing_term_names as $term_id => $name_lower) {
                    foreach ($words as $word) {
                        if (stripos($name_lower, $word) !== false) {
                            $assigned_terms[] = $term_id;
                            break; // Avoid duplicate for same term
                        }
                    }
                }

                $assigned_terms = array_unique($assigned_terms);

                // If no existing term matched, create new category using full CSV category
                if (empty($assigned_terms)) {
                    $new_term = wp_insert_term($category_raw, self::TAXONOMY);
                    if (!is_wp_error($new_term) && isset($new_term['term_id'])) {
                        $assigned_terms[] = (int)$new_term['term_id'];
                    }
                }

                if (!empty($assigned_terms)) {
                    wp_set_post_terms($post_id, $assigned_terms, self::TAXONOMY, false);
                }
            }




            // --- Handle ACF repeater ---
            if (function_exists('have_rows') && function_exists('add_row')) {
                if (get_post_meta($post_id, self::ACF_REPEATER_KEY, false)) {
                    if (function_exists('delete_field')) delete_field(self::ACF_REPEATER_KEY, $post_id);
                    else delete_post_meta($post_id, self::ACF_REPEATER_KEY);
                }
                $options_list = $this->split_options($options_raw, $delim);
                $colors = self::DEFAULT_COLORS;
                $count = 0;
                foreach ($options_list as $idx => $label_raw) {
                    if ($count >= $max_options) break;
                    $label = wp_strip_all_tags($label_raw);
                    if ($label === '') continue;
                    $color = $colors[$idx % count($colors)];
                    add_row(self::ACF_REPEATER_KEY, [self::ACF_LABEL_KEY => $label, self::ACF_COLOR_KEY => $color], $post_id);
                    $count++;
                }
            } else {
                $options_list = array_slice($this->split_options($options_raw, $delim), 0, $max_options);
                update_post_meta($post_id, '_imported_wheel_options', $options_list);
            }

            // --- Yoast SEO meta ---
            if (!empty($meta_title)) update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
            if (!empty($meta_description)) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
                wp_update_post(['ID' => $post_id, 'post_excerpt' => $meta_description]);
            }
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $title);
        }

        fclose($fh);

        set_transient('wheel_csv_import_summary_' . get_current_user_id(), [
            'processed' => $rows_processed,
            'created' => $rows_created,
            'updated' => $rows_updated,
            'skipped' => $rows_skipped,
            'errors' => $errors,
            'dry_run' => $dry_run ? 1 : 0,
        ], 30);

        wp_safe_redirect(admin_url('tools.php?page=wheel-csv-importer&imported=1'));
        exit;
    }

    public function maybe_show_notice() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wheel-csv-importer') return;
        if (!isset($_GET['imported'])) return;

        $key = 'wheel_csv_import_summary_' . get_current_user_id();
        $summary = get_transient($key);
        if (!$summary) return;
        delete_transient($key);

        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Wheel CSV Import — Summary</strong></p>
            <ul>
                <li>Rows processed: <?php echo intval($summary['processed']); ?></li>
                <li>Created: <?php echo intval($summary['created']); ?></li>
                <li>Updated: <?php echo intval($summary['updated']); ?></li>
                <li>Skipped: <?php echo intval($summary['skipped']); ?></li>
                <li>Dry run: <?php echo $summary['dry_run'] ? 'Yes' : 'No'; ?></li>
            </ul>
            <?php if (!empty($summary['errors'])): ?>
                <p><strong>Errors:</strong></p>
                <ul>
                    <?php foreach ($summary['errors'] as $err): ?>
                        <li><?php echo esc_html($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    protected function get_post_by_title_and_cpt($title, $cpt) {
        return get_page_by_title($title, OBJECT, $cpt);
    }

    protected function split_options($raw, $delim) {
        if ($raw === '') return [];
        $items = explode($delim, $raw);
        $items = array_map('trim', $items);
        $items = array_filter($items, fn($v)=>$v!=='');
        return array_values($items);
    }
}

new Wheel_CSV_Importer_UI();

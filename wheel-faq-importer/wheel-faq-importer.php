<?php
/**
 * Plugin Name: Wheel FAQ Importer
 * Description: Import FAQs to wheel post type via CSV (supports title or URL matching)
 */

if (!defined('ABSPATH')) exit;

// Admin menu page
add_action('admin_menu', function () {
    add_menu_page(
        'Wheel FAQ Importer',
        'Wheel FAQ Importer',
        'manage_options',
        'wheel-faq-importer',
        'wheel_faq_importer_page'
    );
});

function wheel_faq_importer_page() {
    ?>
    <div class="wrap">
        <h2>Wheel FAQ Importer</h2>

        <form method="post" enctype="multipart/form-data">
            <input type="file" name="faq_csv" required>
            <input type="submit" name="upload_faqs" class="button button-primary" value="Upload CSV">
        </form>

        <?php
        if (isset($_POST['upload_faqs'])) {
            wheel_import_faqs();
        }
        ?>
    </div>
    <?php
}

function wheel_import_faqs() {

    if (!isset($_FILES['faq_csv'])) {
        echo "<p style='color:red'>No file selected.</p>";
        return;
    }

    $file = $_FILES['faq_csv']['tmp_name'];
    if (!$file) {
        echo "<p style='color:red'>File upload error.</p>";
        return;
    }

    $handle = fopen($file, "r");
    if (!$handle) {
        echo "<p style='color:red'>Unable to read file.</p>";
        return;
    }

    $row = 0;

    while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {

        $row++;
        if ($row == 1) continue; // Skip header

        // CSV Columns
        $wheel_title = trim($data[0]);   // Column A
        $faq_title   = sanitize_text_field($data[1]); // Column B
        $faq_content = wp_kses_post($data[2]);        // Column C
        $wheel_url   = isset($data[3]) ? trim($data[3]) : ""; // Column D

        if (!$wheel_title && !$wheel_url) {
            echo "<p style='color:red'>❌ Row $row skipped: No title or URL provided.</p>";
            continue;
        }

        // =====================================
        // 1️⃣ MATCH WHEEL BY TITLE
        // =====================================
        $wheel_post = null;

        if (!empty($wheel_title)) {
            $wheel_post = get_page_by_title($wheel_title, OBJECT, 'wheel');
        }

        // =====================================
        // 2️⃣ MATCH WHEEL BY URL → SLUG METHOD
        // =====================================
        if (!$wheel_post && !empty($wheel_url)) {

            $wheel_url = rtrim($wheel_url, '/'); // Remove trailing slash
            $slug = basename($wheel_url); // Extract last part

            if (!empty($slug)) {
                $found = get_posts([
                    'name'        => $slug,
                    'post_type'   => 'wheel',
                    'numberposts' => 1
                ]);

                if (!empty($found)) {
                    $wheel_post = $found[0];
                }
            }
        }

        // =====================================
        // 3️⃣ IF STILL NOT FOUND → SKIP
        // =====================================
        if (!$wheel_post) {
            echo "<p style='color:red'>❌ Wheel NOT found for Row $row → 
                  Title: <strong>{$wheel_title}</strong> | URL: <strong>{$wheel_url}</strong></p>";
            continue;
        }

        $post_id = $wheel_post->ID;

        // =====================================
        // 4️⃣ GET EXISTING FAQS
        // =====================================
        $existing_faqs = get_field('wheel_faqs', $post_id);
        if (!is_array($existing_faqs)) $existing_faqs = [];

        // =====================================
        // 5️⃣ CHECK FOR DUPLICATE FAQ TITLE
        // =====================================
        $duplicate = false;
        foreach ($existing_faqs as $faq) {
            if (trim($faq['faq_title']) === $faq_title) {
                $duplicate = true;
                break;
            }
        }

        if ($duplicate) {
            echo "<p style='color:orange'>⚠️ Duplicate skipped for <strong>{$wheel_post->post_title}</strong> → {$faq_title}</p>";
            continue;
        }

        // =====================================
        // 6️⃣ ADD FAQ
        // =====================================
        $existing_faqs[] = [
            'faq_title'   => $faq_title,
            'faq_content' => $faq_content
        ];

        update_field('wheel_faqs', $existing_faqs, $post_id);

        echo "<p style='color:green'>✔ FAQ added to <strong>{$wheel_post->post_title}</strong></p>";
    }

    fclose($handle);

    echo "<h3 style='color:blue'>Import Completed</h3>";
}
?>

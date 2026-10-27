

<?php



if (!defined('ABSPATH')) {

    exit;

}



class SpeedafTariffManager

{

    private const OPTION_KEY = 'sefrelshop_speedaf_tariff';

    private const DRAFT_KEY = 'sefrelshop_speedaf_tariff_draft';

    private const HISTORY_KEY = 'sefrelshop_speedaf_tariff_history';

    private const VAT_RATE = 0.075;

    private const MAX_HISTORY = 10;

    private const MAX_CSV_SIZE = 100000;



    public function registerHooks(): void

    {

        add_action('admin_menu', [$this, 'registerMenu']);



        add_action(

            'admin_post_sefrelshop_speedaf_save_draft',

            [$this, 'saveDraft']

        );



        add_action(

            'admin_post_sefrelshop_speedaf_activate_draft',

            [$this, 'activateDraft']

        );



        add_action(

            'admin_post_sefrelshop_speedaf_export_csv',

            [$this, 'exportCsv']

        );



        add_action(

            'admin_post_sefrelshop_speedaf_import_csv',

            [$this, 'importCsv']

        );

    }



    public function registerMenu(): void

    {

        add_submenu_page(

            'woocommerce',

            'Speedaf Tariff',

            'Speedaf Tariff',

            'manage_woocommerce',

            'sefrelshop-speedaf-tariff',

            [$this, 'renderPage']

        );

    }



    /**

     * Default agreed tariff, excluding VAT.

     * Keys 1-20 represent 0.5-10.0 kg.

     * Zone indexes 0-3 represent Zones 1-4.

     */

    private function getDefaultTariff(): array

    {

        return [

            1  => [3016, 4950, 6624, 8100],

            2  => [3616, 5301, 6984, 8460],

            3  => [3920, 6003, 7362, 8838],

            4  => [4224, 6354, 8100, 9198],

            5  => [4528, 6714, 8460, 9936],

            6  => [5128, 7065, 8838, 10296],

            7  => [5432, 7767, 9198, 10674],

            8  => [5744, 8118, 9936, 11034],

            9  => [6040, 8487, 10296, 11772],

            10 => [6648, 8838, 10674, 12141],

            11 => [7248, 9891, 12717, 14310],

            12 => [7552, 10242, 13113, 14706],

            13 => [7856, 10602, 13518, 15102],

            14 => [8456, 11304, 14310, 15507],

            15 => [8760, 11655, 14706, 16290],

            16 => [9056, 12006, 15102, 16686],

            17 => [9664, 12717, 15507, 17082],

            18 => [9960, 13068, 16290, 17874],

            19 => [10264, 13419, 16686, 18279],

            20 => [10872, 13770, 17082, 18675],

        ];

    }



    /**

     * Validate tariff structure and whole-number prices.

     */

    private function validateTariff($tariff): bool

    {

        if (!is_array($tariff) || count($tariff) !== 20) {

            return false;

        }



        $expected = $this->getDefaultTariff();



        foreach ($expected as $unit => $defaultRow) {

            if (

                !isset($tariff[$unit]) ||

                !is_array($tariff[$unit]) ||

                count($tariff[$unit]) !== 4

            ) {

                return false;

            }



            for ($zone = 0; $zone < 4; $zone++) {

                if (!array_key_exists($zone, $tariff[$unit])) {

                    return false;

                }



                $price = $tariff[$unit][$zone];



                if (

                    !(

                        is_int($price) ||

                        (

                            is_string($price) &&

                            ctype_digit($price)

                        )

                    ) ||

                    (int) $price < 1 ||

                    (int) $price > 100000000

                ) {

                    return false;

                }

            }

        }



        return true;

    }



    private function getActiveTariff(): array

    {

        $active = get_option(self::OPTION_KEY, null);



        if ($this->validateTariff($active)) {

            return $active;

        }



        return $this->getDefaultTariff();

    }



    private function canManage(): bool

    {

        return current_user_can('manage_woocommerce');

    }



    /**

     * Read and validate the tariff submitted through the admin table.

     */

    private function readSubmittedTariff(): array

    {

        $submitted = isset($_POST['tariff']) && is_array($_POST['tariff'])

            ? wp_unslash($_POST['tariff'])

            : [];



        $tariff = [];



        for ($unit = 1; $unit <= 20; $unit++) {

            if (

                !isset($submitted[$unit]) ||

                !is_array($submitted[$unit])

            ) {

                throw new InvalidArgumentException(

                    'Missing tariff row for ' . ($unit / 2) . ' kg.'

                );

            }



            for ($zone = 0; $zone < 4; $zone++) {

                if (

                    !isset($submitted[$unit][$zone]) ||

                    !is_scalar($submitted[$unit][$zone])

                ) {

                    throw new InvalidArgumentException(

                        'Missing price for ' .

                        ($unit / 2) .

                        ' kg, Zone ' .

                        ($zone + 1) .

                        '.'

                    );

                }



                $raw = trim((string) $submitted[$unit][$zone]);



                if (

                    $raw === '' ||

                    !preg_match('/^\d+$/', $raw)

                ) {

                    throw new InvalidArgumentException(

                        'Prices must be whole numbers in naira.'

                    );

                }



                $price = (int) $raw;



                if ($price < 1 || $price > 100000000) {

                    throw new InvalidArgumentException(

                        'A price is outside the permitted range.'

                    );

                }



                $tariff[$unit][$zone] = $price;

            }

        }



        if (!$this->validateTariff($tariff)) {

            throw new InvalidArgumentException(

                'The submitted tariff is incomplete or invalid.'

            );

        }



        return $tariff;

    }



    public function saveDraft(): void

    {

        if (!$this->canManage()) {

            wp_die(

                esc_html__(

                    'You are not allowed to manage tariffs.',

                    'sefrelshop'

                )

            );

        }



        check_admin_referer('sefrelshop_speedaf_save_draft');



        try {

            $tariff = $this->readSubmittedTariff();



            update_option(self::DRAFT_KEY, $tariff, false);



            $this->redirectWithNotice('draft_saved');

        } catch (InvalidArgumentException $exception) {

            $this->redirectWithNotice('invalid_draft');

        }

    }



    public function activateDraft(): void

    {

        if (!$this->canManage()) {

            wp_die(

                esc_html__(

                    'You are not allowed to manage tariffs.',

                    'sefrelshop'

                )

            );

        }



        check_admin_referer('sefrelshop_speedaf_activate_draft');



        $draft = get_option(self::DRAFT_KEY, null);



        if (!$this->validateTariff($draft)) {

            $this->redirectWithNotice('invalid_draft');

        }



        $active = get_option(self::OPTION_KEY, null);



        // Archive the previous saved active tariff before replacing it.

        if ($this->validateTariff($active)) {

            $history = get_option(self::HISTORY_KEY, []);



            if (!is_array($history)) {

                $history = [];

            }



            array_unshift(

                $history,

                [

                    'tariff' => $active,

                    'changed_at' => current_time('mysql'),

                    'changed_by' => get_current_user_id(),

                ]

            );



            $history = array_slice(

                $history,

                0,

                self::MAX_HISTORY

            );



            update_option(self::HISTORY_KEY, $history, false);

        }



        update_option(self::OPTION_KEY, $draft, false);



        $this->redirectWithNotice('tariff_activated');

    }



    /**

     * Export the saved active tariff, or the defaults if none is saved.

     */

    public function exportCsv(): void

    {

        if (!$this->canManage()) {

            wp_die(

                esc_html__(

                    'You are not allowed to export tariffs.',

                    'sefrelshop'

                )

            );

        }



        check_admin_referer('sefrelshop_speedaf_export_csv');



        $saved = get_option(self::OPTION_KEY, null);

        $hasSavedActiveTariff = $this->validateTariff($saved);



        $tariff = $hasSavedActiveTariff

            ? $saved

            : $this->getDefaultTariff();



        $filename = $hasSavedActiveTariff

            ? 'speedaf-active-tariff.csv'

            : 'speedaf-default-tariff.csv';



        nocache_headers();



        header('Content-Type: text/csv; charset=utf-8');



        header(

            'Content-Disposition: attachment; filename="' .

            $filename .

            '"'

        );



        $output = fopen('php\://output', 'w');



        if ($output === false) {

            wp_die(

                esc_html__(

                    'Unable to generate the tariff CSV.',

                    'sefrelshop'

                )

            );

        }



        // UTF-8 BOM improves spreadsheet compatibility.

        fwrite($output, "\xEF\xBB\xBF");



        fputcsv(

            $output,

            [

                'Weight (kg)',

                'Zone 1 Base Price (NGN, excl VAT)',

                'Zone 2 Base Price (NGN, excl VAT)',

                'Zone 3 Base Price (NGN, excl VAT)',

                'Zone 4 Base Price (NGN, excl VAT)',

            ],

            ',',

            '"',

            ''

        );



        for ($unit = 1; $unit <= 20; $unit++) {

            fputcsv(

                $output,

                [

                    number_format($unit / 2, 1, '.', ''),

                    $tariff[$unit][0],

                    $tariff[$unit][1],

                    $tariff[$unit][2],

                    $tariff[$unit][3],

                ],

                ',',

                '"',

                ''

            );

        }



        fclose($output);

        exit;

    }



    /**

     * Import a CSV and save it as a draft only.

     */

    public function importCsv(): void

    {

        if (!$this->canManage()) {

            wp_die(

                esc_html__(

                    'You are not allowed to import tariffs.',

                    'sefrelshop'

                )

            );

        }



        check_admin_referer('sefrelshop_speedaf_import_csv');



        try {

            if (

                !isset($_FILES['tariff_csv']) ||

                !is_array($_FILES['tariff_csv']) ||

                !isset(

                    $_FILES['tariff_csv']['error'],

                    $_FILES['tariff_csv']['tmp_name'],

                    $_FILES['tariff_csv']['size']

                )

            ) {

                throw new InvalidArgumentException(

                    'No CSV file was uploaded.'

                );

            }



            $file = $_FILES['tariff_csv'];



            if ($file['error'] !== UPLOAD_ERR_OK) {

                throw new InvalidArgumentException(

                    'The upload did not complete.'

                );

            }



            if (

                !is_string($file['tmp_name']) ||

                !is_uploaded_file($file['tmp_name'])

            ) {

                throw new InvalidArgumentException(

                    'The uploaded file is invalid.'

                );

            }



            if (

                !is_numeric($file['size']) ||

                (int) $file['size'] < 1 ||

                (int) $file['size'] > self::MAX_CSV_SIZE

            ) {

                throw new InvalidArgumentException(

                    'The CSV file is empty or too large.'

                );

            }



            $tariff = $this->parseTariffCsv($file['tmp_name']);



            // Only update the draft after the entire CSV is valid.

            // The active tariff is not changed by importing.

            update_option(self::DRAFT_KEY, $tariff, false);



            $this->redirectWithNotice('import_success');

        } catch (InvalidArgumentException $exception) {

            $this->redirectWithNotice('import_failed');

        }

    }



    /**

     * Parse the exact CSV format generated by exportCsv().

     */

    private function parseTariffCsv(string $path): array

    {

        $handle = fopen($path, 'r');



        if ($handle === false) {

            throw new InvalidArgumentException(

                'Unable to read the CSV file.'

            );

        }



        try {

            $expectedHeader = [

                'Weight (kg)',

                'Zone 1 Base Price (NGN, excl VAT)',

                'Zone 2 Base Price (NGN, excl VAT)',

                'Zone 3 Base Price (NGN, excl VAT)',

                'Zone 4 Base Price (NGN, excl VAT)',

            ];



            $prefix = fread($handle, 3);



            if ($prefix !== "\xEF\xBB\xBF") {

              rewind($handle);

            }



            $header = fgetcsv($handle, 0, ',', '"', '');



            if (!is_array($header)) {

                throw new InvalidArgumentException(

                    'Missing CSV header.'

                );

            }
            if ($header !== $expectedHeader) {
                throw new InvalidArgumentException(
                    'CSV headers do not match the required format.'
                );
            }



            $tariff = [];



            for ($unit = 1; $unit <= 20; $unit++) {

                $row = fgetcsv($handle, 0, ',', '"', '');

                if (

                    !is_array($row) ||

                    count($row) !== 5

                ) {

                    throw new InvalidArgumentException(

                        'CSV must contain exactly 20 valid data rows.'

                    );

                }



                $weightText = trim((string) $row[0]);

                $expectedWeight = $unit / 2;



                if (

                    $weightText === '' ||

                    !is_numeric($weightText) ||

                    !is_finite((float) $weightText) ||

                    abs(

                        (float) $weightText - $expectedWeight

                    ) > 0.000001

                ) {

                    throw new InvalidArgumentException(

                        'Weight bands must be complete and in ascending order.'

                    );

                }



                $prices = [];



                for ($zone = 0; $zone < 4; $zone++) {

                    $raw = trim((string) $row[$zone + 1]);



                    if (

                        $raw === '' ||

                        !preg_match('/^\d+$/', $raw)

                    ) {

                        throw new InvalidArgumentException(

                            'Prices must be whole numbers in naira.'

                        );

                    }



                    $price = (int) $raw;



                    if (

                        $price < 1 ||

                        $price > 100000000

                    ) {

                        throw new InvalidArgumentException(

                            'A price is outside the permitted range.'

                        );

                    }



                    $prices[$zone] = $price;

                }



                $tariff[$unit] = $prices;

            }



            // Reject additional non-empty rows after the 20th band.

            while (

                ($extra = fgetcsv($handle, 0, ',', '"', '')) !== false

            ) {

                if ($extra === [null] || $extra === []) {

                    continue;

                }



                $hasContent = false;



                foreach ($extra as $cell) {

                    if (trim((string) $cell) !== '') {

                        $hasContent = true;

                        break;

                    }

                }



                if ($hasContent) {

                    throw new InvalidArgumentException(

                        'CSV contains unexpected extra rows.'

                    );

                }

            }



            if (!$this->validateTariff($tariff)) {

                throw new InvalidArgumentException(

                    'The imported tariff is invalid.'

                );

            }



            return $tariff;

        } finally {

            fclose($handle);

        }

    }



    private function redirectWithNotice(string $notice): void

    {

        wp_safe_redirect(

            add_query_arg(

                [

                    'page' => 'sefrelshop-speedaf-tariff',

                    'sefrelshop_notice' => $notice,

                ],

                admin_url('admin.php')

            )

        );



        exit;

    }



    private function renderNotice(): void

    {

        $notice = isset($_GET['sefrelshop_notice'])

            ? sanitize_key(wp_unslash($_GET['sefrelshop_notice']))

            : '';



        $messages = [

            'draft_saved' => [

                'success',

                'Draft tariff saved. It is not active yet.',

            ],

            'tariff_activated' => [

                'success',

                'Tariff activated successfully.',

            ],

            'invalid_draft' => [

                'error',

                'Tariff was not saved or activated. Check that all 80 prices are valid.',

            ],

            'import_success' => [

                'success',

                'CSV validated and saved as a draft. The active tariff has not changed.',

            ],

            'import_failed' => [

                'error',

                'CSV import failed. Check the headers, 20 weight bands, and all 80 prices. The existing draft and active tariff were not changed.',

            ],

        ];



        if (!isset($messages[$notice])) {

            return;

        }



        [$type, $message] = $messages[$notice];



        printf(

            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',

            esc_attr($type),

            esc_html($message)

        );

    }



    public function renderPage(): void

    {

        if (!$this->canManage()) {

            wp_die(

                esc_html__(

                    'You are not allowed to access this page.',

                    'sefrelshop'

                )

            );

        }



        $active = $this->getActiveTariff();



        $draft = get_option(self::DRAFT_KEY, null);

        $hasDraft = $this->validateTariff($draft);



        $editing = $hasDraft ? $draft : $active;



        $savedActive = get_option(self::OPTION_KEY, null);

        $hasSavedActive = $this->validateTariff($savedActive);



        echo '<div class="wrap">';

        echo '<h1>Speedaf Tariff Management</h1>';



        $this->renderNotice();



        echo '<p>Manage Speedaf shipping base prices. VAT of 7.5% is added automatically to shipping quotes.</p>';



        echo '<p><strong>Operational limits:</strong> maximum 10 kg per waybill and maximum parcel dimensions of 30 × 30 × 30 cm.</p>';



        echo '<p><strong>Tariff currently being edited:</strong> ';

        echo esc_html(

            $hasDraft ? 'Saved draft' : 'Active tariff / built-in defaults'

        );

        echo '</p>';



        /*

         * CSV EXPORT FORM

         * Exports the active tariff or built-in defaults, never the draft.

         */

        echo '<h2>Export Tariff CSV</h2>';



        echo '<p>Export source: <strong>';

        echo esc_html(

            $hasSavedActive

                ? 'Saved active tariff'

                : 'Built-in default tariff (no valid active tariff saved)'

        );

        echo '</strong>. Prices exclude VAT. Saved drafts are not exported.</p>';



        echo '<form method="post" action="'

            . esc_url(admin_url('admin-post.php'))

            . '">';



        echo '<input type="hidden" name="action" value="sefrelshop_speedaf_export_csv">';



        wp_nonce_field('sefrelshop_speedaf_export_csv');



        echo '<p><button type="submit" class="button">Export Tariff CSV</button></p>';



        echo '</form>';



        /*

         * CSV IMPORT FORM

         * A valid import replaces the draft only.

         */

        echo '<h2>Import Tariff CSV</h2>';



        echo '<p>Upload a CSV using the exported headers and all 20 weight bands. A successful import saves a draft only and does not activate it.</p>';



        echo '<form method="post" enctype="multipart/form-data" action="'

            . esc_url(admin_url('admin-post.php'))

            . '">';



        echo '<input type="hidden" name="action" value="sefrelshop_speedaf_import_csv">';



        wp_nonce_field('sefrelshop_speedaf_import_csv');



        echo '<input type="file" name="tariff_csv" accept=".csv,text/csv" required>';



        echo '<p><button type="submit" class="button">Import CSV as Draft</button></p>';



        echo '</form>';



        /*

         * EDITABLE TARIFF TABLE

         */

        echo '<h2>Edit Tariff Prices</h2>';



        echo '<p>Enter whole-number base prices in naira, excluding VAT. Save as a draft first, review the values, and activate only when ready.</p>';



        echo '<form method="post" action="'

            . esc_url(admin_url('admin-post.php'))

            . '">';



        echo '<input type="hidden" name="action" value="sefrelshop_speedaf_save_draft">';



        wp_nonce_field('sefrelshop_speedaf_save_draft');



        echo '<table class="widefat striped"><thead><tr>';

        echo '<th>Weight (kg)</th>';



        for ($zone = 1; $zone <= 4; $zone++) {

            echo '<th>Zone ' . esc_html((string) $zone) . ' Base (₦)</th>';

            echo '<th>Zone ' . esc_html((string) $zone) . ' + VAT (₦)</th>';

        }



        echo '</tr></thead><tbody>';



        for ($unit = 1; $unit <= 20; $unit++) {

            $weight = $unit / 2;



            echo '<tr>';



            echo '<td>' . esc_html(number_format($weight, 1)) . '</td>';



            for ($zone = 0; $zone < 4; $zone++) {

                $base = (int) $editing[$unit][$zone];



                // Display total quote using the same VAT rounding approach.

                $vat = (int) round($base * self::VAT_RATE);

                $total = $base + $vat;



                echo '<td><input type="number" min="1" max="100000000" step="1" required name="tariff['

                    . esc_attr((string) $unit)

                    . ']['

                    . esc_attr((string) $zone)

                    . ']" value="'

                    . esc_attr((string) $base)

                    . '" style="width:120px"></td>';



                echo '<td>₦' . esc_html(number_format($total)) . '</td>';

            }



            echo '</tr>';

        }



        echo '</tbody></table>';



        echo '<p><button type="submit" class="button button-primary">Save Draft</button></p>';



        echo '</form>';



        /*

         * ACTIVATE DRAFT FORM

         */

        if ($hasDraft) {

            echo '<hr>';

            echo '<h2>Activate Saved Draft</h2>';



            echo '<p>Activation changes the tariff used by the calculator. Review all prices before continuing.</p>';



            echo '<form method="post" action="'

                . esc_url(admin_url('admin-post.php'))

                . '">';



            echo '<input type="hidden" name="action" value="sefrelshop_speedaf_activate_draft">';



            wp_nonce_field('sefrelshop_speedaf_activate_draft');



            echo '<p><button type="submit" class="button button-secondary">Activate Saved Draft</button></p>';



            echo '</form>';

        }



        /*

         * REVISION HISTORY

         */

        echo '<hr>';

        echo '<h2>Previous Tariff Revisions</h2>';



        $history = get_option(self::HISTORY_KEY, []);



        if (!is_array($history) || $history === []) {

            echo '<p>No previous activated revisions have been archived yet.</p>';

        } else {

            echo '<table class="widefat striped">';

            echo '<thead><tr><th>Date</th><th>Changed by User ID</th><th>Revision</th></tr></thead>';

            echo '<tbody>';



            foreach ($history as $index => $revision) {

                if (!is_array($revision)) {

                    continue;

                }



                echo '<tr>';



                echo '<td>'

                    . esc_html((string) ($revision['changed_at'] ?? 'Unknown'))

                    . '</td>';



                echo '<td>'

                    . esc_html((string) ($revision['changed_by'] ?? 'Unknown'))

                    . '</td>';



                echo '<td>#'

                    . esc_html((string) ($index + 1))

                    . '</td>';



                echo '</tr>';

            }



            echo '</tbody></table>';



            echo '<p>Previous revisions are recorded for reference. Restore/rollback controls have not yet been implemented.</p>';

        }



        echo '</div>';

    }

}

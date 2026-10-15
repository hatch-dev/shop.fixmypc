<?php

namespace App\Models\Helper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Utils
{

    public static function isUploadable($url) {
        return self::isImageUrl($url) && config('env.media.STORAGE') != config('env.media.URL');
    }

    public static function isImageUrl($url) {
        // A regular expression pattern to identify if it's a URL with common image file extensions
        $pattern = "/^https?:\/\/.*\.(jpeg|jpg|png|gif|webp|bmp|tif|svg)$/i";

        // Additional pattern to catch URLs ending with "-jpg", "-jpeg", etc.
        $pattern_with_dash = "/^https?:\/\/.*\-(jpeg|jpg|png|gif|webp|bmp|tif|svg)$/i";

        // Checks if the URL matches either of the patterns
        return preg_match($pattern, trim($url)) === 1 || preg_match($pattern_with_dash, trim($url)) === 1;
    }

    public static function copyImageFromUrl($url, $prefix = null) {

        // Initialize cURL session
        $ch = curl_init();

        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        // Set User-Agent
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537');

        // Disable SSL Verification (for testing purposes only)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);


        // Execute cURL session and get the content
        $image_content = curl_exec($ch);

        // Check for cURL errors
        if (curl_errno($ch)) {
            // Handle error, e.g., print error message
            echo 'Curl error: ' . curl_error($ch);
            return false;
        }

        // Close cURL session
        curl_close($ch);

        if ($image_content !== false) {
            // Save to a temporary file
            $tmpfname = tempnam(sys_get_temp_dir(), 'downloaded_image');
            file_put_contents($tmpfname, $image_content);


            // Create an UploadedFile instance, which has the getOriginalClientExtension() method
            $file = new UploadedFile($tmpfname, basename($url), mime_content_type($tmpfname), 0, true, true);

            $uploaded_image = [];

            // Now you can use this $file with your uploadImage method
            $uploaded_image = FileHelper::uploadImage($file, $prefix);

            // Don’t forget to delete the temporary file after you are done
            unlink($tmpfname);
            unset($file);

            // Check if the image was uploaded successfully
            if ($uploaded_image['name']) {
                // echo 'Image uploaded successfully!';
                return $uploaded_image['name'];
            } else {
                // echo 'Failed to upload the image.';
                return false;
            }
        } else {
            echo 'Failed to fetch the image from the URL.';
            return false;
        }
    }


    public static function findCommonElements($arrays) {
        if (count($arrays) === 0) {
            return [];
        }

        // Use the first array as the base for comparison
        $baseArray = $arrays[0];

        // Find the common elements using array_intersect
        $commonElements = array_intersect($baseArray, ...$arrays);

        return $commonElements;
    }



    public static function startsWith($string, $startString) {
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
    }


    public static function scanDir($dir) {
        $ignored = array('.', '..', '.svn', '.htaccess');

        $files = array();
        foreach (scandir($dir) as $file) {
            if (in_array($file, $ignored)) continue;
            $files[$file] = filemtime($dir . '/' . $file);
        }

        arsort($files);
        $files = array_keys($files);

        return $files;
    }


    public static function getRequest($url, $request){
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_TIMEOUT => 20,

            // CURLOPT_SSL_VERIFYHOST => 0,
            // CURLOPT_SSL_VERIFYPEER => 0,

            CURLOPT_HTTPHEADER => array(
                "Base: {$request->url('/')}",
                //"Base: https://admin.ishop.com",//
            )
        ));

        $response = @curl_exec($ch);



        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch) > 0) {
            throw new \Exception(__('lang.failed') . ": " . curl_error($ch));
        }

        if ($responseCode == 404) {

            $body = @json_decode($response);


            throw new \Exception($body->data->form[0]);

        } else if ($responseCode !== 200) {

            throw new \Exception( __('lang.error', ['status' => $responseCode]));
        }

        $body = @json_decode($response);

        if ($body === false && json_last_error() !== JSON_ERROR_NONE) {

            throw new \Exception( __('lang.error_parse'));
        }

        return $body;

    }


    public static function seperateLangData($data, $langFields){
        $langData = [];
        $mainData = [];

        foreach ($data as $key => $val) {
            if (!in_array($key, $langFields)) {
                $mainData[$key] = $val;
            } else {
                $langData[$key] = $val;
            }
        }

        return [$langData, $mainData];

    }

    public static function orderDetailRedirect(){
        return config('env.url.CLIENT_BASE_URL') . config('env.redirect.ORDER_DETAIL_REDIRECT');
    }

    public static function frontendSocialRedirect(){
        return config('env.url.CLIENT_BASE_URL') . config('env.redirect.FRONTEND_SOCIAL_REDIRECT');
    }

    /**
     * Where the reset email points. The token identifies the request; the
     * email is carried alongside so the page can show whose account it is.
     */
    public static function passwordResetLink($token, $email){
        return rtrim(config('env.url.CLIENT_BASE_URL'), '/')
            . '/reset-password?token=' . urlencode($token)
            . '&email=' . urlencode($email);
    }

    public static function backendSocialRedirect(){
        return config('env.url.APP_URL') . config('env.redirect.BACKEND_SOCIAL_REDIRECT');
    }

    public static function userCan($user, $role){
        if(is_null($user) || !$user->can($role)){
            return response()->json(Validation::unauthorized());
        }
        return false;
    }

    public static function isDataOwner($user, $data){
        if(is_null($user) || ($user->id != $data->admin_id)){
            return response()->json(Validation::unauthorized());
        }
        return false;
    }

    public static function generateRandomString($length = 10) {
        return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
    }

    public static function cacheRemember($cacheKey, $query, $expiration = 60 * 60 * 24){

        return cache()->remember($cacheKey, $expiration, $query);
    }

    /**
     * Drop a cached entry.
     *
     * Needed so a "product not found" result is not held for the full 24-hour
     * window — publishing the product would otherwise leave it unreachable.
     */
    public static function cacheForget($cacheKey){

        return cache()->forget($cacheKey);
    }

    public static function convertTimeToUTCzone($str, $userTimezone, $format = 'Y-m-d H:i:s'){


        $new_str = new \DateTime($str, new \DateTimeZone( $userTimezone  ) );


        $new_str->setTimeZone(new \DateTimeZone('UTC'));
        return $new_str->format( $format);
    }

    public  static function convertTimeToUSERzone($str, $userTimezone, $format = 'Y-m-d H:i:s'){
        if(empty($str)){
            return '';
        }

        $new_str = new \DateTime($str, new \DateTimeZone('UTC') );
        $new_str->setTimeZone(new \DateTimeZone( $userTimezone ));
        return $new_str->format( $format);
    }

    public  static function idGenerator($model){
        $lastValue = $model::select('id')->orderBy('created_at','desc')->first();
        if($lastValue && $lastValue->id){
            $lastId = substr($lastValue->id,5);
            $lastId = (int)$lastId + 3;
        }else{
            $lastId = 111;
        }
        $id = rand(6, 9) . rand(100, 999) . rand(0, 5) . $lastId;
        return $id;
    }

    public static function formatAddress($address){
        if($address){
            $addressArr = [];
            array_push($addressArr, $address->address_1);
            array_push($addressArr, $address->address_2);
            array_push($addressArr, $address->city);
            array_push($addressArr, $address->state);
            array_push($addressArr, $address->country);
            array_push($addressArr, $address->zip);

            $filtered = array_filter($addressArr, function ($element) {
                return '' !== trim($element);
            });

            return join(', ', $filtered);
        }
        return 'N/A';
    }

    public static function formatDate($date, $format = 'h:i a, d M, y'){
        return Carbon::parse($date)->format($format);
    }


    /**
     * A password for an account the customer never asked to set one for.
     *
     * It has to satisfy the same rule the signup form enforces - ten or more
     * characters with a capital and a digit - or they could not later re-enter
     * it anywhere the rule is applied. The alphabet drops the characters that
     * are easily misread when a password is copied out of an email by hand:
     * no O/0, no I/l/1.
     */
    public static function readablePassword($length = 14)
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $alphabet = $upper . $lower . $digits;

        // Seeded so the required classes are present whatever the draw gives.
        $characters = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)]
        ];

        for ($i = count($characters); $i < max($length, 10); $i++) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Without this the first three positions are always upper/lower/digit.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    public static function formatErrors($errors)
    {
        $errors_arr = [];
        foreach ($errors as $key => $value) {
            foreach ($errors[$key] as $error) {
                array_push($errors_arr, $error);
            }
        }
        return $errors_arr;
    }

    public static function orderId($item)
    {
        return self::formatDate($item->created_at, 'Ydm') . $item->id . $item->user_id . $item->status;
    }

    /**
     * The money on an order, in the key names this application has always used
     * for it (the invoice PDF, the order emails, the admin order screen and the
     * POS all read these).
     *
     * Every figure now comes from OrderSummary, which reads only what the order
     * recorded. Two things used to be re-derived here from live configuration:
     *
     *  - the voucher discount was recalculated from the voucher's current type,
     *    rate and cap, so editing a coupon rewrote the discount on every past
     *    order that had used it;
     *  - tax was multiplied by the ordered quantity rather than the payable
     *    quantity, so an order carrying a "buy X get Y free" bundle printed
     *    more VAT on its receipt than the customer was charged.
     *
     * `subtotal` keeps its original meaning - the goods at the price actually
     * charged - because the templates reading it present it as the line tax and
     * shipping are added to. `original_subtotal` and `discount` are the two
     * extra rows that let a receipt show the same "Subtotal / Product Discount"
     * pair the customer approved at checkout.
     */
    public static function calcPrice($order, $inventories = null) {

        // $inventories lets a caller summarising a page of orders fetch every
        // line price in one query; see OrderSummary::inventoriesFor().
        $summary = OrderSummary::for($order, $inventories);

        $calculated = [];

        $calculated['original_subtotal'] = $summary['subtotal'];
        $calculated['discount'] = $summary['discount'];
        $calculated['subtotal'] = $summary['goods'];
        $calculated['shipping_price'] = $summary['shipping'];
        $calculated['bundle_offer'] = $summary['bundle_offer'];
        $calculated['tax'] = $summary['tax'];
        $calculated['voucher_price'] = $summary['voucher'];
        $calculated['flash_discount'] = $summary['flash_discount'];
        // Beside the other order-wide discounts, so the email and the PDF can
        // print a row for it rather than leaving a gap in the total.
        $calculated['bundle_discount'] = $summary['bundle_discount'];
        $calculated['loyalty_discount'] = $summary['loyalty_discount'];
        $calculated['loyalty_label'] = $summary['loyalty_label'];
        $calculated['points_discount'] = $summary['points_discount'];
        /*
         * The same order as a VAT document states it. Emails and the
         * server-rendered invoice read these so they say the same thing
         * as the storefront and the admin, from the same split.
         */
        $calculated['ex_vat'] = $summary['ex_vat'];
        $calculated['vat'] = $summary['vat'];
        $calculated['inc_vat'] = $summary['inc_vat'];
        /* The goods gross: subtotal_inc_vat - discount_inc_vat = inc_vat. */
        $calculated['subtotal_inc_vat'] = $summary['subtotal_inc_vat'];
        $calculated['discount_inc_vat'] = $summary['discount_inc_vat'];
        $calculated['vat_breakdown'] = $summary['vat_breakdown'];
        $calculated['total_price'] = $summary['total'];
        // A split payment: the wallet part, and what the order's method took.
        $calculated['wallet_paid'] = $summary['wallet_paid'];
        $calculated['amount_due'] = $summary['amount_due'];

        return $calculated;
    }

    public  static function jsDecryption($encrypted){
        $key = hex2bin("0123456470abcdef0123456789abcdef");
        $iv =  hex2bin("abcdef1876343516abcdef9876543210");
        $decrypted = openssl_decrypt($encrypted, 'AES-128-CBC', $key, OPENSSL_ZERO_PADDING, $iv);
        return trim($decrypted);
    }


    public  static function decryptLicence($encrypted, $secret_key, $secret_iv){
        $encrypt_method = "AES-256-CBC";

        // hash
        $key = hash('sha256', $secret_key);

        // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
        $iv = substr(hash('sha256', $secret_iv), 0, 16);

        $decryptToken = openssl_decrypt(base64_decode($encrypted), $encrypt_method, $key, 0, $iv);

        return json_decode($decryptToken);
    }

    /**
     * A new order's public number: ORD and seven random digits, e.g.
     * ORD1578945. Random rather than sequential, so it says nothing about
     * how many orders the shop takes. Checked against every order, deleted
     * ones included (the unique index on orders.order is the backstop).
     * Orders placed before 2026-10-15 keep their old numbers.
     */
    public static function generateOrderNumber()
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $number = 'ORD' . random_int(1000000, 9999999);

            if (!DB::table('orders')->where('order', $number)->exists()) {
                return $number;
            }
        }

        throw new \RuntimeException('No free order number found');
    }

    /** Payment references (SumUp checkouts). Not order numbers - see generateOrderNumber(). */
    public  static function generateTrackingId($item){
        $now = Carbon::now();
        return Utils::formatDate($now, 'Ydm') . Utils::generateRandomString(5) . $item['user_id'];
    }



    public static function makeKeyword($string) {
        $removeWords = [
            'a', 'an', 'the', 'and', 'or', 'but', 'about', 'above', 'after', 'against',
            'along', 'among', 'around', 'at', 'before', 'behind', 'below', 'beneath',
            'beside', 'between', 'by', 'down', 'during', 'for', 'from', 'in', 'inside',
            'into', 'near', 'of', 'off', 'on', 'out', 'over', 'through', 'to', 'toward',
            'under', 'up', 'with', 'within', 'without'
        ];

        // Split the string into words
        $words = preg_split("/[\s,]+/", $string);

        // Filter out the articles, prepositions, and numbers
        $filteredWords = array_filter($words, function($word) use ($removeWords) {
            return !in_array(strtolower($word), $removeWords) && !is_numeric($word);
        });

        // Join the remaining words into a comma-separated string
        $result = implode(",", $filteredWords);

        return $result;
    }




}

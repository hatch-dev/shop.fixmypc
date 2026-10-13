@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make(null, $lang);
    $recipient = isset($recipient) ? $recipient : null;
    $unsubscribeUrl = isset($unsubscribeUrl) ? $unsubscribeUrl : null;

    // Turn the WYSIWYG HTML into readable text: block tags become newlines,
    // links keep their href, everything else is stripped.
    $plain = preg_replace('/<\s*br\s*\/?>/i', "\n", $body);
    $plain = preg_replace('/<\/\s*(p|div|h[1-6]|li|tr)\s*>/i', "\n", $plain);
    $plain = preg_replace('/<\s*li[^>]*>/i', '- ', $plain);
    $plain = preg_replace_callback('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function ($m) {
        $text = trim(strip_tags($m[2]));
        return $text && $text !== $m[1] ? $text . ' (' . $m[1] . ')' : $m[1];
    }, $plain);
    $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim(preg_replace("/\n{3,}/", "\n\n", $plain));
@endphp
{!! $plain !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
@if ($recipient)

{!! __('lang.mail_newsletter_reason', ['store' => $brand->store_short], $lang) !!}
{!! __('lang.mail_sent_to', ['email' => $recipient], $lang) !!}
@endif
@if ($unsubscribeUrl)
{!! __('lang.mail_unsubscribe', [], $lang) !!}: {!! $unsubscribeUrl !!}
@endif

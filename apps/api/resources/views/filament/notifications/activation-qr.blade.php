{{--
    The activation token as a QR, rendered once.

    The token is not stored anywhere, so this view is the only place it ever
    appears. Reopening the page cannot bring it back — that is what stops a code
    from being handed out twice (§6.2).
--}}
@php
    use chillerlan\QRCode\{QRCode, QROptions};

    $svg = $token === null ? null : (new QRCode(new QROptions([
        'outputType' => QRCode::OUTPUT_MARKUP_SVG,
        'eccLevel' => QRCode::ECC_M,
        'scale' => 5,
        'imageBase64' => false,
    ])))->render($token);
@endphp

<div class="space-y-3">
    <p class="text-sm">{{ $body ?? '' }}</p>

    @if ($svg)
        <div class="rounded-lg bg-white p-3 inline-block">
            {!! $svg !!}
        </div>
    @else
        <p class="text-sm text-danger-600">
            The token could not be rendered. Revoke this code and issue a new one.
        </p>
    @endif
</div>

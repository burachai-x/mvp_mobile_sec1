{{--
    The activation token as a QR.

    Only a hash of the token is stored, so this is the one place it ever appears.
    Closing the window loses it; pressing Show QR again issues a different one
    (§6.2).
--}}
@php
    use chillerlan\QRCode\{QRCode, QROptions};

    $svg = null;

    if ($token !== null) {
        $svg = (new QRCode(new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_M,
            // Only sets the coordinate system: the markup carries a viewBox and
            // no dimensions, so without the size below it stretches to fill
            // whatever box it lands in — which is why it filled the modal.
            'scale' => 1,
            // Inline SVG in an HTML document must not carry an XML prolog.
            'svgAddXmlHeader' => false,
            'imageBase64' => false,
        ])))->render($token);

        // Sized here rather than with utility classes: this markup is generated,
        // and the panel stylesheet is Filament's prebuilt one, so a class we
        // invent may simply not exist in it.
        $svg = str_replace('<svg ', '<svg width="256" height="256" ', $svg);
    }
@endphp

<div style="display:flex; justify-content:center; padding:0.5rem 0">
    @if ($svg)
        <div style="background:#fff; padding:1rem; border-radius:0.5rem; line-height:0">
            {!! $svg !!}
        </div>
    @else
        <p style="color:#b91c1c; font-size:0.875rem">
            The token could not be rendered. Revoke this code and issue a new one.
        </p>
    @endif
</div>

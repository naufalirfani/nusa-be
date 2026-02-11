<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        @page { margin:0; padding:0 }
        html,body{ margin:0; padding:0 }
        *{ box-sizing: border-box }
        body{ position: relative; }
        .certificate-container{ position: relative; }
        .background{ position:absolute; top:0; left:0; width:100%; height:100%; z-index:0 }
        .element{ position:absolute; z-index:1 }
        .text-element{ white-space: pre-wrap; word-wrap: break-word }
    </style>
</head>
<body>
@php
    $width = $design['width'] ?? 2000;
    $height = $design['height'] ?? 1414;

    $backgroundBase64 = '';
    if(!empty($design['background'])){
        $possible = [ public_path('storage/' . $design['background']), public_path('bg_Sertifikat.png') ];
        foreach($possible as $p){
            if(file_exists($p)){
                $img = file_get_contents($p);
                $mime = mime_content_type($p) ?: 'image/png';
                $backgroundBase64 = 'data:' . $mime . ';base64,' . base64_encode($img);
                break;
            }
        }
    }
@endphp

<div class="certificate-container" style="width:{{ $width }}px; height:{{ $height }}px;">

    @if($backgroundBase64)
        <img src="{{ $backgroundBase64 }}" class="background" alt="Background" />
    @endif

    {{-- Elements --}}
    @foreach($design['elements'] ?? [] as $element)
        @if(($element['type'] ?? '') === 'text')
            @php
                $text = $element['value'] ?? '';
                foreach($placeholders as $k => $v){ $text = str_replace($k, $v, $text); }

                $x = $element['x'] ?? 0; $y = $element['y'] ?? 0;
                $fontSize = $element['fontSize'] ?? 32;
                $fontFamily = $element['fontFamily'] ?? 'Arial';
                $fontStyle = $element['fontStyle'] ?? 'normal';
                $fill = $element['fill'] ?? '#000000';
                $align = $element['align'] ?? 'left';
                $widthEl = $element['width'] ?? 'auto';
                $textDecoration = $element['textDecoration'] ?? 'none';
                $lineHeight = $element['lineHeight'] ?? 1.2;

                $fontWeight = strpos($fontStyle, 'bold') !== false ? 'bold' : 'normal';
                $fontStyleCss = strpos($fontStyle, 'italic') !== false ? 'italic' : 'normal';

                $style = "left: {$x}px; top: {$y}px; font-size: {$fontSize}px; font-family: '{$fontFamily}', Arial, sans-serif; font-weight: {$fontWeight}; font-style: {$fontStyleCss}; color: {$fill}; text-align: {$align}; text-decoration: {$textDecoration}; line-height: {$lineHeight};";
                if(is_numeric($widthEl)) $style .= " width: {$widthEl}px;";
            @endphp

            <div class="element text-element" style="{!! $style !!}">{{ $text }}</div>

        @elseif(($element['type'] ?? '') === 'image')
            @php
                $imagePath = $element['path'] ?? '';
                $possible = [ public_path($imagePath), public_path('storage/' . $imagePath) ];
                $full = null; foreach($possible as $p){ if(file_exists($p)){ $full = $p; break; } }
                if($full){
                    $img = file_get_contents($full);
                    $mime = mime_content_type($full) ?: 'image/png';
                    $base64 = 'data:' . $mime . ';base64,' . base64_encode($img);

                    $x = $element['x'] ?? 0; $y = $element['y'] ?? 0;
                    $w = $element['width'] ?? 'auto'; $h = $element['height'] ?? 'auto'; $fitted = $element['fitted'] ?? false;
                    $style = "left: {$x}px; top: {$y}px;"; if(is_numeric($w)) $style .= " width: {$w}px;"; if(is_numeric($h)) $style .= " height: {$h}px;"; if($fitted) $style .= " object-fit: contain;";
                }
            @endphp

            @if(!empty($base64))
                <img src="{{ $base64 }}" class="element" style="{!! $style !!}" alt="" />
            @endif

        @endif
    @endforeach

</div>
</body>
</html>

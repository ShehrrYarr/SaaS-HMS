@php
    // TCPDF embeds inline images with the "@<base64>" syntax instead of data URIs.
    $img = fn ($src) => '@'.substr($src, strpos($src, ',') + 1);
@endphp
<table cellpadding="2">
    <tr>
        <td width="50%"><img src="{{ $img($logo) }}" height="40"></td>
        <td width="50%" align="right"><b style="font-size:12px;">{{ $hospital->name }}</b><br><span style="color:#6b7280; font-size:8px;">{{ $hospital->fullAddress() }}<br>{{ $hospital->phone }}</span></td>
    </tr>
</table>
<hr>
@include('pdf.partials.lab-report-body', ['img' => $img])
<p style="font-size:7px; color:#6b7280;">This PDF is digitally signed by {{ $hospital->name }}. Verify online: {{ $verifyUrl }}</p>

{{-- One product picture, shown 64 px wide (the file is 128 px, so it stays sharp on a phone). With no picture: a linen tile with the first letter. --}}
@if (! empty($url))
    <img src="{{ $url }}" width="64" height="64" alt="{{ $name }}" style="display:block;width:64px;height:64px;border:0;background-color:#EDE4D3;">
@else
    <table role="presentation" width="64" height="64" cellpadding="0" cellspacing="0" bgcolor="#EDE4D3" style="width:64px;height:64px;background-color:#EDE4D3;">
        <tr>
            <td align="center" valign="middle" width="64" height="64" bgcolor="#EDE4D3" style="width:64px;height:64px;background-color:#EDE4D3;font-family:Georgia,'Times New Roman',serif;font-size:26px;line-height:64px;color:#1B2A41;">{{ \App\Support\EmailThumbs::initial($name) }}</td>
        </tr>
    </table>
@endif

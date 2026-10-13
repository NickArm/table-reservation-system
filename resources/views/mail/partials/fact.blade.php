{{--
    One labelled line of a voucher. A table row rather than a definition list,
    because Outlook renders dl without the indent and the two columns collapse.
--}}
<tr>
    <td width="110" valign="top"
        style="padding:0 12px 10px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:13px;line-height:1.5;color:#78716c;background-color:#ffffff;">
        {{ $label }}
    </td>
    <td valign="top"
        style="padding:0 0 10px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1c1917;background-color:#ffffff;">
        {{ $value }}
        @if (filled($note ?? null))
            <div style="font-size:13px;color:#78716c;padding-top:2px;">{{ $note }}</div>
        @endif
    </td>
</tr>

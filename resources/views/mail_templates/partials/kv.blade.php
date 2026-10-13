@php
    /**
     * Label / value rows.
     *
     * Two cells rather than a flex row with space-between, because Word
     * renders the flex version as two stacked lines. On phones the .stack
     * class in the layout turns them into stacked lines deliberately.
     *
     * @var object $brand
     * @var array  $rows   [['label' => ..., 'value' => ..., 'strong' => bool, 'tone' => 'positive'|null], ...]
     */
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    @foreach ($rows as $i => $row)
        @php
            $strong = !empty($row['strong']);
            $tone = isset($row['tone']) ? $row['tone'] : null;
            $valueColor = $tone === 'positive' ? $brand->positive : $brand->ink;
        @endphp
        <tr>
            <td class="muted" valign="top" align="{{ isset($dir) && $dir === 'rtl' ? 'right' : 'left' }}"
                style="padding:{{ $i === 0 ? '0' : '9px' }} 12px 0 0; font-family:Arial,Helvetica,sans-serif;
                       font-size:14px; line-height:22px; color:{{ $brand->muted }};">
                {{ $row['label'] }}
            </td>
            <td valign="top" align="{{ isset($dir) && $dir === 'rtl' ? 'left' : 'right' }}"
                style="padding:{{ $i === 0 ? '0' : '9px' }} 0 0 0; font-family:Arial,Helvetica,sans-serif;
                       font-size:14px; line-height:22px; font-weight:{{ $strong ? 'bold' : 'normal' }};
                       color:{{ $valueColor }};"
                class="{{ $tone === 'positive' ? '' : 'ink' }}">
                {!! $row['value'] !!}
            </td>
        </tr>
    @endforeach
</table>

@props(['name'])
{{-- Ikon garis sederhana 24×24 (digambar untuk ANTAREJA). --}}
<svg {{ $attributes->merge(['class' => 'i']) }} viewBox="0 0 24 24" aria-hidden="true">@switch($name)
@case('flag')<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>@break
@case('layers')<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>@break
@case('home')<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>@break
@case('server')<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>@break
@case('switch')<rect x="2" y="8" width="20" height="8" rx="1.5"/><path d="M6 12h1M9.5 12h1M13 12h1M16.5 12h1"/>@break
@case('router')<rect x="3" y="12" width="18" height="7" rx="1.5"/><path d="M7 15.5h.01M11 15.5h.01"/><path d="M8 12V8M16 12V5"/>@break
@case('wifi')<path d="M2.5 9a14 14 0 0 1 19 0"/><path d="M6 12.5a9 9 0 0 1 12 0"/><path d="M9.5 16a4 4 0 0 1 5 0"/><path d="M12 19.5h.01"/>@break
@case('antenna')<path d="M12 10v11M8 21h8"/><path d="M8.5 6.5a5 5 0 0 0 0 7M15.5 6.5a5 5 0 0 1 0 7"/><path d="M5.5 3.5a9 9 0 0 0 0 13M18.5 3.5a9 9 0 0 1 0 13"/><circle cx="12" cy="10" r="1.3"/>@break
@case('camera')<path d="M3 8h12v9H3z"/><path d="M15 11l6-3v9l-6-3"/>@break
@case('nvr')<rect x="3" y="6" width="18" height="12" rx="1.5"/><path d="M7 14h.01M10 14h7"/><circle cx="12" cy="10" r="1.5"/>@break
@case('shield')<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/>@break
@case('battery')<rect x="3" y="7" width="16" height="10" rx="1.5"/><path d="M21 10.5v3"/><path d="M7 10v4M10.5 10v4"/>@break
@case('box')<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>@break
@case('map-pin')<path d="M12 21s7-6.2 7-11.5a7 7 0 0 0-14 0C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>@break
@case('list')<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>@break
@case('users')<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>@break
@case('audit')<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 12h6M9 16h6"/>@break
@case('search')<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('edit')<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>@break
@case('trash')<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>@break
@case('refresh')<path d="M20 11a8 8 0 0 0-14.3-4.9L4 8"/><path d="M4 3v5h5"/><path d="M4 13a8 8 0 0 0 14.3 4.9L20 16"/><path d="M20 21v-5h-5"/>@break
@case('arrow-down')<path d="M12 4v16M6 14l6 6 6-6"/>@break
@case('arrow-up')<path d="M12 20V4M6 10l6-6 6 6"/>@break
@case('rotate')<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/>@break
@case('wave')<path d="M2 12h3l3-7 4 14 3-9 2 2h5"/>@break
@case('gauge')<path d="M4 17a8 8 0 1 1 16 0"/><path d="M12 17l4-5"/>@break
@case('plug')<path d="M9 3v5M15 3v5"/><path d="M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v4"/>@break
@case('plug-off')<path d="M9 3v5M15 3v5"/><path d="M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v4"/><path d="M3 3l18 18"/>@break
@case('check')<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>@break
@case('x')<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>@break
@case('alert')<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>@break
@case('info')<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5h.01"/>@break
@case('moon')<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>@break
@case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
@case('logout')<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4M6 12h11"/>@break
@case('key')<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/>@break
@case('pulse')<path d="M3 12h4l2-5 4 10 2-5h6"/>@break
@case('wrench')<path d="M14.5 6.5a4 4 0 0 0-5.3 5.3L4 17l3 3 5.2-5.2a4 4 0 0 0 5.3-5.3l-2.6 2.6-2.4-.6-.6-2.4z"/>@break
@case('bell')<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>@break
@case('share')<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 10.8l7.6-3.6M8.2 13.2l7.6 3.6"/>@break
@case('monitor')<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>@break
@case('expand')<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>@break
@case('volume')<path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M16.5 8.5a5 5 0 0 1 0 7"/>@break
@case('volume-x')<path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M17 9.5l4 5M21 9.5l-4 5"/>@break
@case('chart')<path d="M4 20V4M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>@break
@case('database')<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/>@break
@case('download')<path d="M12 4v11M7 10l5 5 5-5"/><path d="M5 20h14"/>@break
@case('terminal')<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9l3 3-3 3M12.5 15H17"/>@break
@case('grid')<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>@break
@default<circle cx="12" cy="12" r="9"/>
@endswitch</svg>

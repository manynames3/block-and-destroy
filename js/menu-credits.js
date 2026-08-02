/* ============================================================================
 * Squeak & Destroy — Credits submenu screen (DOM).
 * Static, polished credits page. Registered via CA.Menu.register('credits').
 * No data fetching. Freepik attribution is REQUIRED — keep it.
 * ==========================================================================*/
(function(){
  if(window.CA && CA.i18n && CA.i18n.addEN) CA.i18n.addEN({
    'Készítők':'Credits',
    'Cuki, de halálos':'Turn-based voxel mayhem.',
    'Készítette':'Original game by',
    'Köszönet':'Thanks',
    'Köszönjük, hogy játszol! Külön köszönet a tesztelőknek, a csapatnak és a capybaráknak. 🐹':
      'Thanks for playing! This voxel reskin preserves the upstream game credits and attribution.',
    'Technológia':'Technology',
    'HTML5 Canvas és tiszta JavaScript — keretrendszer nélkül.':
      'HTML5 Canvas and vanilla JavaScript — no framework.',
    'Grafika':'Art',
    'Karakter-illusztrációk: Freepik (Free License, attribúció kötelező).':
      'Upstream illustration assets: Freepik (Free License, attribution required).',
    'Fegyver-ikonok: game-icons.net — Lorc, Delapouite és közreműködők (CC BY 3.0).':
      'Weapon icons: game-icons.net — Lorc, Delapouite and contributors (CC BY 3.0).',
    'UI, tábla és partikulák: Kenney.nl (CC0).':'UI, board and particles: Kenney.nl (CC0).',
    'Zene & hang':'Music & Sound',
    'Menüzene: „Monkeys Spinning Monkeys" — Kevin MacLeod (incompetech.com), CC BY 4.0.':
      'Menu music: “Monkeys Spinning Monkeys” — Kevin MacLeod (incompetech.com), CC BY 4.0.',
    'Játékzene: „The Verdant Grove" — Abstraction (CC0).':
      'Game music: “The Verdant Grove” — Abstraction (CC0).',
    'Hangeffektek: Kenney.nl (CC0) és saját felvételek.':
      'Sound effects: Kenney.nl (CC0) and original recordings.',
    'Adatvédelmi nyilatkozat':'Privacy Policy',
    '🎮 Hamarosan Steamen és App Store-ban':'Unofficial fan project — not affiliated with Mojang or Microsoft.',
  });

  function build(API){
    const { screen, body } = API.screen('👏','Készítők');

    const panel = API.panel();
    panel.style.textAlign = 'center';
    panel.style.maxWidth = '520px';
    panel.style.margin = '0 auto';
    panel.style.gap = 'var(--s5)';

    // ---- hero -----------------------------------------------------------
    const hero = API.el('div','section');
    hero.style.alignItems = 'center';
    hero.style.gap = 'var(--s2)';
    const logo = API.el('div','home-wordmark','<span>BLOCK</span><span>&amp; DESTROY</span>');
    logo.setAttribute('aria-label','Block and Destroy');
    logo.style.cssText='font-size:clamp(38px,8vw,68px);margin:0 auto;filter:drop-shadow(0 10px 24px rgba(0,0,0,.45))';
    const tag = API.el('div','muted','<span data-t="Cuki, de halálos">Cuki, de halálos</span>');
    tag.style.cssText='font-style:italic;font-weight:800;color:var(--orange,#E8711A);font-size:15px;margin-top:var(--s1)';
    hero.append(logo, tag);

    // ---- section builder ------------------------------------------------
    function section(headT, bodyHtml){
      const s = API.el('div','section');
      s.style.alignItems = 'center';
      s.style.gap = 'var(--s2)';
      const h = API.el('h3',null,'<span data-t="'+API.esc(headT)+'">'+API.esc(headT)+'</span>');
      h.style.color = 'var(--accent)';
      const p = API.el('div',null, bodyHtml);
      p.style.color = 'var(--ink)';
      p.style.lineHeight = '1.5';
      s.append(h, p);
      return s;
    }

    // ---- author: John Crystal + profile pic + company --------------------
    const author = API.el('div','section');
    author.style.alignItems='center'; author.style.gap='var(--s2)';
    const ah = API.el('h3',null,'<span data-t="Készítette">Készítette</span>');
    ah.style.color='var(--accent)';
    const pic = API.el('img'); pic.alt='John Crystal';
    pic.style.cssText='width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--gold);margin:0 auto;display:block;box-shadow:0 6px 18px rgba(0,0,0,.4)';
    pic.onerror=function(){ pic.style.display='none'; };   // graceful if the pic isn't on the server yet
    // set src AFTER cssText (which would otherwise reset display) — and not at
    // all when the photo isn't shipped, so we never fire a guaranteed 404.
    if(CA._isMissingArt && CA._isMissingArt('assets/john.jpg')) pic.style.display='none';
    else pic.src='assets/john.jpg';
    const who = API.el('div',null,'<strong>John Crystal</strong><br><span class="muted">Original Squeak &amp; Destroy · Los Polos Amigos Kft.</span>');
    author.append(ah, pic, who);

    const thanks = section('Köszönet',
      '<span class="muted" data-t="Köszönjük, hogy játszol! Külön köszönet a tesztelőknek, a csapatnak és a capybaráknak. 🐹">'+
      CA.t('Köszönjük, hogy játszol! Külön köszönet a tesztelőknek, a csapatnak és a capybaráknak. 🐹')+'</span>');

    const tech = section('Technológia',
      '<span class="muted" data-t="HTML5 Canvas és tiszta JavaScript — keretrendszer nélkül.">'+
      CA.t('HTML5 Canvas és tiszta JavaScript — keretrendszer nélkül.')+'</span>');

    function line(key){
      return '<span class="muted" data-t="'+API.esc(key)+'">'+CA.t(key)+'</span>';
    }

    const art = section('Grafika',
      '<span class="muted">Voxel characters are original procedural Canvas drawings; no official Minecraft art is included.</span><br>'+
      line('Karakter-illusztrációk: Freepik (Free License, attribúció kötelező).')+'<br>'+
      line('Fegyver-ikonok: game-icons.net — Lorc, Delapouite és közreműködők (CC BY 3.0).')+'<br>'+
      line('UI, tábla és partikulák: Kenney.nl (CC0).'));

    const audio = section('Zene & hang',
      line('Menüzene: „Monkeys Spinning Monkeys" — Kevin MacLeod (incompetech.com), CC BY 4.0.')+'<br>'+
      line('Játékzene: „The Verdant Grove" — Abstraction (CC0).')+'<br>'+
      line('Hangeffektek: Kenney.nl (CC0) és saját felvételek.'));

    // ---- steam ----------------------------------------------------------
    const upstream = API.el('div','muted','Based on <a href="https://github.com/lospolosadwords-dev/squeak-and-destroy" target="_blank" rel="noopener" style="color:var(--orange,#E8711A)">Squeak &amp; Destroy</a>. Source changes are MIT; review upstream asset licenses before redistribution.');
    upstream.style.cssText='font-size:13px;line-height:1.5;margin-top:var(--s2)';

    const steam = API.el('div',null,'<span data-t="🎮 Hamarosan Steamen és App Store-ban">🎮 Hamarosan Steamen és App Store-ban</span>');
    steam.style.fontWeight = '800';
    steam.style.color = 'var(--green)';
    steam.style.fontSize = '15px';
    steam.style.marginTop = 'var(--s2)';

    // ---- privacy policy link (App Store / GDPR) -------------------------
    const privacy = API.el('div','muted',
      '<a href="'+(CA.api?CA.api('privacy.html'):'privacy.html')+'" target="_blank" rel="noopener" style="color:var(--orange,#E8711A)">'+
      '🔒 <span data-t="Adatvédelmi nyilatkozat">Adatvédelmi nyilatkozat</span></a>');
    privacy.style.fontSize = '13px';
    privacy.style.marginTop = 'var(--s2)';

    const foot = API.el('div','muted','Block &amp; Destroy v0.2.2 by BenJam Games · original game © 2026 Los Polos Amigos');
    foot.style.fontSize = '12px';

    panel.append(hero, author, thanks, tech, art, audio, upstream, steam, privacy, foot);
    body.append(panel);

    return { el:screen };
  }

  if(window.CA && CA.Menu && CA.Menu.register) CA.Menu.register('credits', build);
})();

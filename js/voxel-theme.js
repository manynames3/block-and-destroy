/* ============================================================================
 * BLOCK & DESTROY — voxel character theme
 * ----------------------------------------------------------------------------
 * This module deliberately keeps the upstream species ids. Those ids are part of
 * save files, multiplayer messages, passives, ultimates and mode setup. Only the
 * player-facing metadata and procedural Canvas2D art are replaced here.
 *
 * No Mojang/Microsoft textures, skins, models or other game assets are bundled.
 * Every character below is drawn from simple canvas rectangles at runtime.
 * ==========================================================================*/
(function(){
  'use strict';

  var C = CA.config;

  var SKINS = {
    capybara: {
      id:'capybara', name:'Steve', team:'Steve Squad', emoji:'⛏️', type:'human',
      skin:'#b97a56', hair:'#3b2417', shirt:'#2aa7a1', pants:'#354a78', eyes:'#5fc9ff',
      passiveDesc:'Swimmer — stays buoyant longer and drowns more slowly.'
    },
    worm: {
      id:'worm', name:'Alex', team:'Alex Squad', emoji:'🧭', type:'human',
      skin:'#d59a72', hair:'#d4672f', shirt:'#6a9f48', pants:'#6d4831', eyes:'#69c86f', slim:true,
      passiveDesc:'Miner — bores a short tunnel into the terrain.'
    },
    hedgehog: {
      id:'hedgehog', name:'Creeper', team:'Creeper Crew', emoji:'💥', type:'creeper',
      body:'#62b44b', accent:'#3e8f39',
      passiveDesc:'Charged rush — fast movement damages enemies on contact.'
    },
    beaver: {
      id:'beaver', name:'Villager', team:'Villager Union', emoji:'🔔', type:'villager',
      skin:'#a97759', robe:'#7a4d35', accent:'#c49a6c',
      passiveDesc:'Builder — places a sturdy block wall ahead.'
    },
    frog: {
      id:'frog', name:'Slime', team:'Slime Squad', emoji:'🟩', type:'slime',
      body:'#68d36b', accent:'#2f9348',
      passiveDesc:'Bouncy — jumps much higher and farther.'
    },
    bird: {
      id:'bird', name:'Skeleton', team:'Skeleton Guard', emoji:'💀', type:'skeleton',
      bone:'#e6e2d2', accent:'#928f83',
      passiveDesc:'Feather fall — drifts briefly after jumping to slow its fall.'
    },
    koala: {
      id:'koala', name:'Zombie', team:'Zombie Horde', emoji:'🧟', type:'zombie',
      skin:'#5f9b58', hair:'#315039', shirt:'#268d93', pants:'#5a3e7d', eyes:'#281b2d',
      passiveDesc:'Makeshift glider — falls more slowly after a jump.'
    },
    bat: {
      id:'bat', name:'Enderman', team:'Ender Walkers', emoji:'🟪', type:'enderman',
      body:'#17141d', accent:'#ae63ff',
      passiveDesc:'Void drift — floats briefly after jumping.'
    },
    owl: {
      id:'owl', name:'Witch', team:'Witch Coven', emoji:'🧪', type:'witch',
      skin:'#8e6f68', robe:'#4d356d', accent:'#7fd35c',
      passiveDesc:'Potion float — hangs in the air and falls more slowly.'
    },
    fox: {
      id:'fox', name:'Piglin', team:'Piglin Clan', emoji:'🐽', type:'piglin',
      skin:'#d38d78', shirt:'#7d4b42', pants:'#4a3030', accent:'#f1cf54',
      passiveDesc:'Nether sprint — glides briefly and lands softly.'
    },
    cat: {
      id:'cat', name:'Iron Golem', team:'Iron Guard', emoji:'🛡️', type:'golem',
      body:'#d8d0b9', accent:'#7b8e67',
      passiveDesc:'Heavy launch — leaps much higher and farther.'
    },
    penguin: {
      id:'penguin', name:'Snow Golem', team:'Snow Brigade', emoji:'⛄', type:'snowgolem',
      body:'#edf3f4', accent:'#dc7d24',
      passiveDesc:'Ice slide — glides after jumping and falls more slowly.'
    }
  };

  var ORDER = ['capybara','worm','hedgehog','beaver','frog','bird','koala','bat','owl','fox','cat','penguin'];

  function skinOf(u){
    var id = u && (u.species || u.kind);
    return SKINS[id] || SKINS.capybara;
  }

  function teamColor(u){
    try {
      var t = CA.getTeam && CA.getTeam(u.teamId);
      return (t && t.color) || '#e5b642';
    } catch(e){ return '#e5b642'; }
  }

  function hexMix(hex, amount){
    var m = /^#([0-9a-f]{6})$/i.exec(hex || '');
    if(!m) return hex || '#777777';
    var n = parseInt(m[1], 16);
    var target = amount < 0 ? 0 : 255;
    var p = Math.abs(amount);
    var r = n >> 16, g = (n >> 8) & 255, b = n & 255;
    r = Math.round(r + (target-r)*p);
    g = Math.round(g + (target-g)*p);
    b = Math.round(b + (target-b)*p);
    return '#' + ((1<<24) + (r<<16) + (g<<8) + b).toString(16).slice(1);
  }

  function px(ctx, x, y, w, h, fill, stroke){
    ctx.fillStyle = fill;
    ctx.fillRect(Math.round(x), Math.round(y), Math.round(w), Math.round(h));
    if(stroke){
      ctx.strokeStyle = stroke;
      ctx.lineWidth = 0.8;
      ctx.strokeRect(Math.round(x)+0.4, Math.round(y)+0.4, Math.round(w)-0.8, Math.round(h)-0.8);
    }
  }

  function cube(ctx, x, y, w, h, front, depth){
    depth = depth == null ? 2 : depth;
    px(ctx, x, y, w, h, front, hexMix(front,-0.28));
    ctx.fillStyle = hexMix(front,0.20);
    ctx.beginPath();
    ctx.moveTo(x, y); ctx.lineTo(x+depth, y-depth); ctx.lineTo(x+w+depth, y-depth); ctx.lineTo(x+w, y);
    ctx.closePath(); ctx.fill();
    ctx.fillStyle = hexMix(front,-0.20);
    ctx.beginPath();
    ctx.moveTo(x+w, y); ctx.lineTo(x+w+depth, y-depth); ctx.lineTo(x+w+depth, y+h-depth); ctx.lineTo(x+w, y+h);
    ctx.closePath(); ctx.fill();
  }

  function teamPatch(ctx, u, x, y, w){
    var c = teamColor(u);
    px(ctx, x, y, w, 2, c, hexMix(c,-0.32));
    px(ctx, x+w-2, y-1, 2, 2, hexMix(c,0.30));
  }

  function walkAmount(u, a){
    return a && a.state === 'walk' ? Math.sin(a.walkPhase || 0) * 2.2 : 0;
  }

  function drawLimb(ctx, x, y, w, h, color, angle){
    ctx.save();
    ctx.translate(x, y);
    if(angle) ctx.rotate(angle);
    cube(ctx, -w/2, 0, w, h, color, 1);
    ctx.restore();
  }

  function faceEyes(ctx, color, aiming, blink){
    if(blink){
      px(ctx,-4,-10,3,1,color);
      px(ctx, 2,-10,3,1,color);
      return;
    }
    var shift = aiming ? 1 : 0;
    px(ctx,-4+shift,-11,2,2,color);
    px(ctx, 2+shift,-11,2,2,color);
    px(ctx,-3+shift,-11,1,1,'rgba(255,255,255,.75)');
    px(ctx, 3+shift,-11,1,1,'rgba(255,255,255,.75)');
  }

  function drawHuman(ctx, u, a, s){
    var walk = walkAmount(u,a);
    var aiming = !!(a && a._aiming);
    var blink = !!(a && a.blink > 0);
    var legW = s.slim ? 4 : 5;
    drawLimb(ctx,-3,7,legW,10,s.pants, walk*0.11);
    drawLimb(ctx, 3,7,legW,10,hexMix(s.pants,-0.10),-walk*0.11);
    drawLimb(ctx,-8,-2,4,12,hexMix(s.shirt,-0.08),-walk*0.09);
    drawLimb(ctx, 8,-2,4,12,s.skin,aiming ? -0.75 : walk*0.09);
    cube(ctx,-6,-3,12,12,s.shirt,2);
    teamPatch(ctx,u,-6,5,12);
    cube(ctx,-7,-16,14,13,s.skin,2);
    px(ctx,-7,-16,14,4,s.hair);
    px(ctx,-7,-13,3,5,s.hair);
    if(s.id==='capybara'){
      px(ctx,3,-8,4,2,s.hair);
      px(ctx,5,-6,2,2,s.hair);
    } else {
      px(ctx,-6,-12,2,7,s.hair);
      px(ctx,5,-15,2,6,s.hair);
    }
    faceEyes(ctx,s.eyes,aiming,blink);
    px(ctx,-1,-7,3,1,hexMix(s.skin,-0.25));
  }

  function drawZombie(ctx,u,a,s){
    drawHuman(ctx,u,a,s);
    px(ctx,-1,-8,3,2,'#315d34');
    px(ctx,-6,-5,3,2,hexMix(s.skin,-0.25));
    px(ctx,3,-14,3,2,hexMix(s.skin,0.12));
  }

  function drawSkeleton(ctx,u,a,s){
    var walk=walkAmount(u,a), aiming=!!(a&&a._aiming), bone=s.bone;
    drawLimb(ctx,-3,6,3,11,bone, walk*0.12);
    drawLimb(ctx, 3,6,3,11,hexMix(bone,-0.08),-walk*0.12);
    drawLimb(ctx,-7,-2,3,12,bone,-walk*0.10);
    drawLimb(ctx, 7,-2,3,12,bone,aiming?-0.78:walk*0.10);
    px(ctx,-5,-2,10,2,bone);
    px(ctx,-4,1,8,2,bone);
    px(ctx,-5,4,10,2,bone);
    px(ctx,-1,-3,2,10,s.accent);
    teamPatch(ctx,u,-5,6,10);
    cube(ctx,-7,-16,14,13,bone,2);
    px(ctx,-5,-12,4,4,'#28262b');
    px(ctx, 2,-12,4,4,'#28262b');
    px(ctx,-1,-8,3,3,'#4d4a48');
    px(ctx,-4,-5,2,1,'#77736b'); px(ctx,0,-5,2,1,'#77736b'); px(ctx,4,-5,1,1,'#77736b');
  }

  function drawCreeper(ctx,u,a,s){
    if(a && a.rolling){
      ctx.save();
      ctx.rotate(a.rollSpin||0);
      cube(ctx,-10,-10,20,20,s.body,2);
      px(ctx,-6,-5,4,4,'#17261a'); px(ctx,3,-5,4,4,'#17261a');
      px(ctx,-2,0,5,3,'#17261a'); px(ctx,-5,2,3,4,'#17261a'); px(ctx,4,2,3,4,'#17261a');
      teamPatch(ctx,u,-8,7,16);
      ctx.restore();
      return;
    }
    var walk=walkAmount(u,a);
    drawLimb(ctx,-5,8,5,9,hexMix(s.body,-0.10), walk*0.08);
    drawLimb(ctx, 5,8,5,9,s.body,-walk*0.08);
    drawLimb(ctx,-2,8,5,9,s.body,-walk*0.08);
    drawLimb(ctx, 2,8,5,9,hexMix(s.body,-0.08), walk*0.08);
    cube(ctx,-5,-3,10,13,s.body,2);
    teamPatch(ctx,u,-5,5,10);
    cube(ctx,-8,-16,16,14,hexMix(s.body,0.02),2);
    px(ctx,-5,-12,4,4,'#17261a'); px(ctx,2,-12,4,4,'#17261a');
    px(ctx,-2,-8,5,3,'#17261a'); px(ctx,-4,-6,3,3,'#17261a'); px(ctx,3,-6,3,3,'#17261a');
    px(ctx,-7,-15,3,2,hexMix(s.body,0.20)); px(ctx,4,-4,2,4,hexMix(s.body,-0.20));
  }

  function drawVillager(ctx,u,a,s){
    var walk=walkAmount(u,a);
    drawLimb(ctx,-3,8,5,9,hexMix(s.robe,-0.14),walk*0.08);
    drawLimb(ctx, 3,8,5,9,s.robe,-walk*0.08);
    cube(ctx,-7,-3,14,14,s.robe,2);
    teamPatch(ctx,u,-7,7,14);
    cube(ctx,-7,-16,14,13,s.skin,2);
    px(ctx,-5,-12,4,2,'#3b2b25'); px(ctx,2,-12,4,2,'#3b2b25');
    px(ctx,-4,-10,2,2,'#3b5a50'); px(ctx,3,-10,2,2,'#3b5a50');
    cube(ctx,3,-9,5,6,hexMix(s.skin,-0.08),1);
    px(ctx,-6,0,12,4,hexMix(s.robe,-0.18));
    px(ctx,-4,1,8,2,hexMix(s.skin,0.04));
  }

  function drawSlime(ctx,u,a,s){
    var bounce = a && !u.grounded ? 1 : Math.abs(Math.sin((a&&a.t||0)*1.5))*0.4;
    ctx.save();
    ctx.globalAlpha=.86;
    cube(ctx,-12,-11-bounce,24,25,s.body,3);
    ctx.globalAlpha=.22;
    px(ctx,-8,-7-bounce,6,13,'#d9ffd7');
    px(ctx,4,-8-bounce,4,5,'#efffeb');
    ctx.globalAlpha=1;
    px(ctx,-6,-5-bounce,4,4,'#183b22'); px(ctx,3,-5-bounce,4,4,'#183b22');
    px(ctx,-3,2-bounce,7,2,'#245a30');
    teamPatch(ctx,u,-10,10-bounce,20);
    ctx.restore();
  }

  function drawEnderman(ctx,u,a,s){
    var walk=walkAmount(u,a), aiming=!!(a&&a._aiming);
    drawLimb(ctx,-3,4,3,14,s.body,walk*0.12);
    drawLimb(ctx, 3,4,3,14,hexMix(s.body,0.04),-walk*0.12);
    drawLimb(ctx,-6,-6,3,17,s.body,-walk*0.10);
    drawLimb(ctx, 6,-6,3,17,s.body,aiming?-0.75:walk*0.10);
    cube(ctx,-4,-7,8,12,s.body,1);
    teamPatch(ctx,u,-4,2,8);
    cube(ctx,-7,-19,14,11,s.body,2);
    px(ctx,-5,-15,5,2,s.accent); px(ctx,1,-15,5,2,s.accent);
    px(ctx,-3,-15,2,1,'#f3c7ff'); px(ctx,3,-15,2,1,'#f3c7ff');
  }

  function drawWitch(ctx,u,a,s){
    var walk=walkAmount(u,a);
    drawLimb(ctx,-3,8,5,9,hexMix(s.robe,-0.18),walk*0.08);
    drawLimb(ctx, 3,8,5,9,s.robe,-walk*0.08);
    ctx.fillStyle=s.robe;
    ctx.beginPath(); ctx.moveTo(-4,-2); ctx.lineTo(-9,12); ctx.lineTo(9,12); ctx.lineTo(4,-2); ctx.closePath(); ctx.fill();
    teamPatch(ctx,u,-7,8,14);
    cube(ctx,-7,-15,14,12,s.skin,2);
    px(ctx,-4,-11,2,2,'#443234'); px(ctx,3,-11,2,2,'#443234');
    cube(ctx,3,-9,5,6,hexMix(s.skin,-0.08),1); px(ctx,6,-6,2,2,s.accent);
    px(ctx,-10,-18,20,3,'#2f203f');
    px(ctx,-7,-24,14,7,s.robe); px(ctx,-4,-29,8,6,hexMix(s.robe,-0.08)); px(ctx,3,-27,5,3,s.robe);
    px(ctx,-7,-20,14,2,s.accent);
  }

  function drawPiglin(ctx,u,a,s){
    var walk=walkAmount(u,a), aiming=!!(a&&a._aiming);
    drawLimb(ctx,-3,7,5,10,s.pants,walk*0.11);
    drawLimb(ctx, 3,7,5,10,hexMix(s.pants,-0.1),-walk*0.11);
    drawLimb(ctx,-8,-2,4,12,s.skin,-walk*0.09);
    drawLimb(ctx, 8,-2,4,12,s.skin,aiming?-0.75:walk*0.09);
    cube(ctx,-6,-3,12,12,s.shirt,2); teamPatch(ctx,u,-6,5,12);
    px(ctx,-10,-13,4,6,s.skin); px(ctx,7,-13,4,6,s.skin);
    cube(ctx,-7,-16,14,13,s.skin,2);
    px(ctx,-4,-12,2,2,'#392a34'); px(ctx,3,-12,2,2,'#392a34');
    px(ctx,-3,-8,7,4,hexMix(s.skin,-0.12)); px(ctx,-2,-7,2,1,'#7d4b56'); px(ctx,2,-7,2,1,'#7d4b56');
    px(ctx,-4,-6,2,3,'#f5e7b0'); px(ctx,5,-6,2,3,'#f5e7b0');
    px(ctx,-6,-15,3,2,s.accent);
  }

  function drawGolem(ctx,u,a,s){
    var walk=walkAmount(u,a);
    drawLimb(ctx,-4,7,6,10,hexMix(s.body,-0.08),walk*0.08);
    drawLimb(ctx, 4,7,6,10,s.body,-walk*0.08);
    drawLimb(ctx,-10,-3,6,15,s.body,-walk*0.05);
    drawLimb(ctx, 10,-3,6,15,hexMix(s.body,-0.04),walk*0.05);
    cube(ctx,-8,-4,16,13,s.body,2); teamPatch(ctx,u,-8,6,16);
    cube(ctx,-7,-16,14,12,hexMix(s.body,0.03),2);
    px(ctx,-4,-12,3,2,'#8b342f'); px(ctx,2,-12,3,2,'#8b342f');
    cube(ctx,3,-9,4,5,hexMix(s.body,-0.1),1);
    px(ctx,-7,-3,3,8,s.accent); px(ctx,-5,2,4,3,hexMix(s.accent,0.12));
    px(ctx,5,-14,2,5,'#a6aa91');
  }

  function drawSnowGolem(ctx,u,a,s){
    var walk=walkAmount(u,a);
    drawLimb(ctx,-11,-2,3,13,'#6c4a2a',-0.35+walk*0.04);
    drawLimb(ctx, 11,-2,3,13,'#6c4a2a',0.35-walk*0.04);
    cube(ctx,-8,1,16,14,s.body,2);
    cube(ctx,-6,-7,12,10,hexMix(s.body,-0.02),2);
    teamPatch(ctx,u,-7,10,14);
    cube(ctx,-7,-18,14,12,s.accent,2);
    px(ctx,-7,-18,14,3,'#5c8235');
    px(ctx,-4,-14,2,2,'#2b201a'); px(ctx,3,-14,2,2,'#2b201a');
    px(ctx,-1,-11,6,2,'#d8a036'); px(ctx,3,-10,5,2,'#d8a036');
    px(ctx,-4,-8,2,1,'#4a2d18'); px(ctx,0,-7,2,1,'#4a2d18'); px(ctx,4,-8,2,1,'#4a2d18');
  }

  function drawVoxel(ctx,u,a){
    var s=skinOf(u);
    ctx.save();
    ctx.imageSmoothingEnabled=false;
    switch(s.type){
      case 'human': drawHuman(ctx,u,a,s); break;
      case 'zombie': drawZombie(ctx,u,a,s); break;
      case 'skeleton': drawSkeleton(ctx,u,a,s); break;
      case 'creeper': drawCreeper(ctx,u,a,s); break;
      case 'villager': drawVillager(ctx,u,a,s); break;
      case 'slime': drawSlime(ctx,u,a,s); break;
      case 'enderman': drawEnderman(ctx,u,a,s); break;
      case 'witch': drawWitch(ctx,u,a,s); break;
      case 'piglin': drawPiglin(ctx,u,a,s); break;
      case 'golem': drawGolem(ctx,u,a,s); break;
      case 'snowgolem': drawSnowGolem(ctx,u,a,s); break;
      default: drawHuman(ctx,u,a,SKINS.capybara);
    }
    ctx.restore();
  }

  function drawDead(ctx,u){
    var t=(u.anim&&u.anim.deathT)||0;
    ctx.save();
    ctx.globalAlpha=Math.max(.28,1-t*.45);
    ctx.translate(0,7);
    ctx.rotate(-Math.PI/2.15);
    ctx.scale(.72,.72);
    drawVoxel(ctx,u,u.anim||{});
    ctx.restore();
    ctx.save();
    ctx.globalAlpha=Math.max(0,.7-t*.65);
    px(ctx,-7,-20-t*22,4,4,'rgba(255,255,255,.78)');
    px(ctx, 1,-25-t*24,3,3,'rgba(196,174,255,.72)');
    px(ctx, 6,-18-t*20,2,2,'rgba(255,255,255,.62)');
    ctx.restore();
  }

  function drawCosmetic(ctx,u,id){
    var top = skinOf(u).type==='enderman' ? -21 : (skinOf(u).type==='witch' ? -31 : -18);
    var tc=teamColor(u);
    switch(id){
      case 'grumpy':
        px(ctx,-5,-13,4,2,'#35231d'); px(ctx,2,-13,4,2,'#35231d'); break;
      case 'pirate':
        px(ctx,-8,top,16,4,'#b8322b'); px(ctx,-6,-11,13,1,'#161616'); px(ctx,2,-12,5,4,'#161616'); break;
      case 'helmet':
        px(ctx,-9,top-2,18,4,'#f5b800','#8f6900'); px(ctx,-7,top-6,14,5,'#f5b800','#8f6900'); break;
      case 'silly':
        px(ctx,-6,-13,5,5,'#fff','#222'); px(ctx,2,-12,5,5,'#fff','#222');
        px(ctx,-3,-11,2,2,'#111'); px(ctx,4,-10,2,2,'#111'); px(ctx,0,-6,3,4,'#ff6f9c'); break;
      case 'glasses':
        ctx.strokeStyle='#202020'; ctx.lineWidth=2;
        ctx.strokeRect(-7,-13,6,6); ctx.strokeRect(2,-13,6,6); px(ctx,-1,-11,3,2,'#202020'); break;
      case 'mohawk':
        px(ctx,-6,top-5,4,5,'#d93a2d'); px(ctx,-2,top-8,4,8,'#d93a2d'); px(ctx,2,top-5,4,5,'#d93a2d'); break;
      case 'cap':
        px(ctx,-8,top-4,14,5,tc,hexMix(tc,-.3)); px(ctx,4,top,7,2,tc); break;
      case 'mustache':
        px(ctx,-5,-7,4,2,'#38251a'); px(ctx,2,-7,4,2,'#38251a'); px(ctx,-2,-8,5,3,'#38251a'); break;
      case 'bow':
        px(ctx,-7,top-5,5,5,'#ff5fa2'); px(ctx,3,top-5,5,5,'#ff5fa2'); px(ctx,-2,top-4,5,4,'#d6336c'); break;
      case 'bandage':
        ctx.save(); ctx.rotate(-.35); px(ctx,-9,-11,18,3,'#f1eadb','#c8bfaa'); ctx.restore(); break;
      case 'headphones':
        px(ctx,-9,-14,3,9,'#2b2b2b'); px(ctx,7,-14,3,9,'#2b2b2b'); px(ctx,-7,top-3,14,3,'#424242'); break;
    }
  }

  CA.VoxelTheme = {
    skins:SKINS,
    order:ORDER.slice(),
    skinOf:skinOf,
    draw:drawVoxel,
    drawDead:drawDead,
    drawCosmetic:drawCosmetic
  };

  ORDER.forEach(function(id){
    var sp=CA.Species && CA.Species[id];
    var skin=SKINS[id];
    if(!sp || !skin) return;
    sp.name=skin.name;
    sp.emoji=skin.emoji;
    sp.passiveDesc=skin.passiveDesc;
    sp.colors={ body:skin.body||skin.skin||skin.bone||'#888888', accent:skin.accent||skin.shirt||'#555555' };
    sp.voxel=true;
    sp.draw=drawVoxel;
  });
  if(CA.Species) CA.Species.list=function(){ return ORDER.slice(); };

  var rosters={
    capybara:['Steve','Mason','Scout','Redstone','Miner'],
    worm:['Alex','Ember','Birch','Copper','Beacon'],
    hedgehog:['Hiss','Fuse','Moss','Spark','Boom'],
    beaver:['Fletcher','Mason','Farmer','Clerk','Nitwit'],
    frog:['Squish','Bounce','Goo','Cube','Lime'],
    bird:['Bones','Flint','Arrow','Rattle','Ivory'],
    koala:['Rotter','Groan','Mossy','Shambler','Dusk'],
    bat:['Echo','Void','Pearl','Rift','Shade'],
    owl:['Hex','Potion','Brew','Ivy','Cackle'],
    fox:['Goldie','Brute','Tusk','Ember','Quartz'],
    cat:['Forge','Anvil','Rust','Rivet','Sentinel'],
    penguin:['Frost','Flurry','Coal','Powder','Drift']
  };
  CA.ROSTER_EN=CA.ROSTER_EN||{};
  CA.ROSTER_HU=CA.ROSTER_HU||{};
  ORDER.forEach(function(id){
    var data={ team:SKINS[id].team, names:rosters[id].slice() };
    CA.ROSTER_EN[id]=data;
    CA.ROSTER_HU[id]={ team:data.team, names:data.names.slice() };
  });
  CA.NAME_POOL_EN=['Scout','Miner','Builder','Ranger','Spark','Quartz','Beacon','Rift','Forge','Frost'];

  if(CA.Weapons && CA.Weapons.LIST){
    var weaponTheme={
      capyslam:['TNT Slam','🧨'], capybomb:['TNT Mega Bomb','🧨'],
      drill:['Diamond Drill','💎'], spikeball:['Creeper Charge','💥'],
      logtoss:['Block Toss','🧱'], bellyflop:['Slime Slam','🟩'], eggbomb:['Spawn Egg','🥚']
    };
    CA.Weapons.LIST.forEach(function(w){
      var themed=weaponTheme[w.id];
      if(themed){ w.name=themed[0]; w.emoji=themed[1]; }
    });
  }
})();

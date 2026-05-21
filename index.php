<?php
require_once 'config/config.php';

$services = [];
$result = $conn->query("SELECT id, name, description, price, category, duration_minutes FROM services WHERE is_active=1 ORDER BY price ASC LIMIT 9");
if ($result) while ($row = $result->fetch_assoc()) $services[] = $row;

$doctors = [];
$result = $conn->query("SELECT id, name, specialty, bio, license_number FROM doctors WHERE is_active=1 ORDER BY name ASC LIMIT 6");
if ($result) while ($row = $result->fetch_assoc()) $doctors[] = $row;

$total_doctors  = (int)$conn->query("SELECT COUNT(*) AS c FROM doctors WHERE is_active=1")->fetch_assoc()['c'];
$total_patients = (int)$conn->query("SELECT COUNT(*) AS c FROM patients")->fetch_assoc()['c'];
$total_appts    = (int)$conn->query("SELECT COUNT(*) AS c FROM appointments WHERE status='completed'")->fetch_assoc()['c'];
$conn->close();

$cat_icons = [
    'Preventive'    => 'bi-shield-check',
    'Restorative'   => 'bi-tools',
    'Cosmetic'      => 'bi-stars',
    'Orthodontics'  => 'bi-arrows-angle-expand',
    'Oral Surgery'  => 'bi-scissors',
    'Endodontics'   => 'bi-activity',
    'Emergency'     => 'bi-lightning',
    'Consultation'  => 'bi-chat-dots',
];

$specialties_color = [
    'General Dentistry'  => '#0d9488',
    'Oral Surgery'       => '#0891b2',
    'Orthodontics'       => '#7c3aed',
    'Cosmetic Dentistry' => '#db2777',
    'Endodontics'        => '#b45309',
    'Periodontics'       => '#16a34a',
];

$testimonials = [
    ['quote' => "Lorem ipsum lorem ipsum lorem ipsum lorem ipsum", 'name' => 'Maria Santos', 'role' => 'Patient since 2022'],
    ['quote' => "Lorem ipsum lorem ipsum lorem ipsum lorem ipsum lorem ipsum lorem ipsum lorem ipsum lorem ipsum", 'name' => 'Jose dela Cruz', 'role' => 'Patient since 2023'],
    ['quote' => "Lorem ipsum lorem ipsum lorem ipsum lorem ipsum Lorem ipsum lorem ipsum lorem ipsum lorem ipsum .", 'name' => 'Ana Bautista', 'role' => 'Patient since 2021'],
    ['quote' => "Lorem ipsum lorem ipsum lorem ipsum lorem ipsum", 'name' => 'Ramon Villanueva', 'role' => 'Patient since 2023'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Vytal Dental – Book Your Appointment Online</title>
<meta name="description" content="Premium dental care in the Philippines. Book appointments online with Vytal Dental's specialist team. Check-ups, whitening, orthodontics, oral surgery and more."/>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;0,800;0,900;1,700;1,800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>

<style>
:root {
  --ink:     #0f1117;
  --ink2:    #1e2330;
  --teal:    #0d9488;
  --teal2:   #0f766e;
  --teal-lt: #f0fdf9;
  --cream:   #fdfaf5;
  --cream2:  #f5f0e8;
  --border:  #e8e3da;
  --slate:   #6b7280;
  --muted:   #9ca3af;
  --white:   #ffffff;
  --radius:  16px;
  --font-display: 'Playfair Display', Georgia, serif;
  --font-body:    'DM Sans', system-ui, sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body { font-family: var(--font-body); background: var(--cream); color: var(--ink); overflow-x: hidden; }
a { text-decoration: none; color: inherit; }
img { display: block; max-width: 100%; }

.reveal { opacity: 0; transform: translateY(32px); transition: opacity .7s ease, transform .7s ease; }
.reveal.visible { opacity: 1; transform: none; }
.reveal-left  { opacity: 0; transform: translateX(-40px); transition: opacity .7s ease, transform .7s ease; }
.reveal-right { opacity: 0; transform: translateX(40px);  transition: opacity .7s ease, transform .7s ease; }
.reveal-left.visible, .reveal-right.visible { opacity: 1; transform: none; }

.nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100;
  padding: 0 5vw;
  display: flex; align-items: center; justify-content: space-between;
  height: 68px;
  transition: background .3s, box-shadow .3s;
}
.nav.scrolled { background: rgba(15,17,23,.92); backdrop-filter: blur(12px); box-shadow: 0 1px 0 rgba(255,255,255,.06); }
.nav-logo { display: flex; align-items: center; gap: 10px; font-family: var(--font-display); font-size: 1.25rem; font-weight: 700; color: #fff; }
.nav-logo i { color: #00c9a7; font-size: 1.4rem; }
.nav-links { display: flex; gap: 32px; }
.nav-links a { font-size: .85rem; font-weight: 500; color: rgba(255,255,255,.6); transition: color .2s; letter-spacing: .02em; }
.nav-links a:hover { color: #fff; }
.nav-cta { display: flex; gap: 10px; align-items: center; }
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 22px; border-radius: 50px; font-family: var(--font-body); font-size: .84rem; font-weight: 600; cursor: pointer; transition: all .2s; border: none; white-space: nowrap; text-decoration: none; }
.btn-outline-white { background: transparent; border: 1.5px solid rgba(255,255,255,.3); color: rgba(255,255,255,.8); }
.btn-outline-white:hover { border-color: #fff; color: #fff; }
.btn-teal { background: var(--teal); color: #fff; }
.btn-teal:hover { background: var(--teal2); transform: translateY(-1px); box-shadow: 0 4px 18px rgba(13,148,136,.35); }
.btn-ink { background: var(--ink); color: #fff; }
.btn-ink:hover { background: var(--ink2); }
.btn-outline-ink { background: transparent; border: 1.5px solid var(--border); color: var(--ink); }
.btn-outline-ink:hover { border-color: var(--ink); }
.nav-mobile-btn { display: none; background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; }

.hero {
  min-height: 100vh;
  background: var(--ink);
  position: relative;
  overflow: hidden;
  display: flex; flex-direction: column; justify-content: center;
  padding: 120px 5vw 80px;
}
.hero-bg {
  position: absolute; inset: 0; pointer-events: none;
}
.hero-blob-1 {
  position: absolute; top: -200px; right: -100px;
  width: 700px; height: 700px; border-radius: 50%;
  background: radial-gradient(circle, rgba(13,148,136,.18) 0%, transparent 70%);
}
.hero-blob-2 {
  position: absolute; bottom: -150px; left: -100px;
  width: 500px; height: 500px; border-radius: 50%;
  background: radial-gradient(circle, rgba(0,201,167,.08) 0%, transparent 70%);
}
.hero-grid-lines {
  position: absolute; inset: 0; opacity: .04;
  background-image: linear-gradient(rgba(255,255,255,.5) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.5) 1px, transparent 1px);
  background-size: 60px 60px;
}
.hero-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(0,201,167,.12); border: 1px solid rgba(0,201,167,.25);
  color: #00c9a7; font-size: .75rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em;
  padding: 5px 14px; border-radius: 50px;
  margin-bottom: 24px;
}
.hero-inner { display: grid; grid-template-columns: 1fr 420px; gap: 60px; align-items: center; position: relative; z-index: 1; max-width: 1200px; margin: 0 auto; width: 100%; }
.hero-headline {
  font-family: var(--font-display);
  font-size: clamp(3rem, 6vw, 5.5rem);
  font-weight: 900; line-height: 1.04; color: #fff;
  margin-bottom: 24px;
}
.hero-headline em { font-style: italic; color: #00c9a7; }
.hero-sub { font-size: 1.05rem; color: rgba(255,255,255,.5); line-height: 1.7; max-width: 500px; margin-bottom: 36px; }
.hero-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 56px; }
.hero-stats { display: flex; gap: 36px; }
.hstat-val { font-family: var(--font-display); font-size: 2.2rem; font-weight: 800; color: #fff; line-height: 1; }
.hstat-label { font-size: .75rem; color: rgba(255,255,255,.4); margin-top: 4px; text-transform: uppercase; letter-spacing: .08em; }
.hero-card-col { display: flex; flex-direction: column; gap: 14px; }
.hero-card {
  background: rgba(255,255,255,.05);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 20px;
  padding: 22px 24px;
  backdrop-filter: blur(8px);
  transition: border-color .3s, transform .3s;
}
.hero-card:hover { border-color: rgba(0,201,167,.3); transform: translateX(-4px); }
.hcard-top { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.hcard-icon { width: 38px; height: 38px; border-radius: 10px; background: rgba(0,201,167,.15); display: flex; align-items: center; justify-content: center; font-size: 1rem; color: #00c9a7; flex-shrink: 0; }
.hcard-title { font-family: var(--font-display); font-size: .95rem; font-weight: 700; color: #fff; }
.hcard-body { font-size: .8rem; color: rgba(255,255,255,.4); line-height: 1.6; }
.hcard-price { margin-top: 10px; font-size: .85rem; font-weight: 700; color: #00c9a7; }
.hero-scroll { position: absolute; bottom: 32px; left: 50%; transform: translateX(-50%); display: flex; flex-direction: column; align-items: center; gap: 8px; color: rgba(255,255,255,.3); font-size: .7rem; text-transform: uppercase; letter-spacing: .12em; z-index: 1; }
.scroll-line { width: 1px; height: 40px; background: linear-gradient(to bottom, transparent, rgba(255,255,255,.3)); animation: scrollPulse 2s infinite; }
@keyframes scrollPulse { 0%,100% { opacity: .3; } 50% { opacity: .8; } }

.section { padding: 96px 5vw; }
.section-inner { max-width: 1200px; margin: 0 auto; }
.section-tag { display: inline-flex; align-items: center; gap: 6px; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; color: var(--teal); background: var(--teal-lt); padding: 4px 12px; border-radius: 50px; margin-bottom: 16px; }
.section-title { font-family: var(--font-display); font-size: clamp(2rem, 4vw, 3rem); font-weight: 800; line-height: 1.15; margin-bottom: 14px; }
.section-sub { font-size: 1rem; color: var(--slate); line-height: 1.7; max-width: 600px; }

.marquee-section { background: var(--teal); overflow: hidden; padding: 18px 0; }
.marquee-track { display: flex; gap: 0; animation: marqueeScroll 25s linear infinite; white-space: nowrap; }
@keyframes marqueeScroll { from { transform: translateX(0); } to { transform: translateX(-50%); } }
.marquee-item { display: inline-flex; align-items: center; gap: 14px; padding: 0 28px; font-family: var(--font-display); font-size: 1rem; font-weight: 700; color: rgba(255,255,255,.85); font-style: italic; }
.marquee-dot { width: 5px; height: 5px; border-radius: 50%; background: rgba(255,255,255,.4); }

.why-section { background: var(--cream); }
.why-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center; }
.why-features { display: grid; gap: 20px; margin-top: 32px; }
.why-feat { display: flex; gap: 16px; align-items: flex-start; }
.wf-icon { width: 42px; height: 42px; border-radius: 12px; background: var(--teal-lt); display: flex; align-items: center; justify-content: center; font-size: 1rem; color: var(--teal); flex-shrink: 0; }
.wf-title { font-weight: 700; font-size: .9rem; margin-bottom: 4px; }
.wf-body { font-size: .82rem; color: var(--slate); line-height: 1.6; }
.why-visual { position: relative; }
.why-img-wrap { border-radius: 28px; overflow: hidden; aspect-ratio: 4/5; background: var(--ink2); position: relative; }
.why-img-placeholder {
  width: 100%; height: 100%;
  background: linear-gradient(135deg, var(--ink2) 0%, #1a2535 100%);
  display: flex; align-items: center; justify-content: center;
}
.why-img-placeholder i { font-size: 5rem; color: rgba(0,201,167,.2); }
.why-float-card {
  position: absolute; bottom: -20px; left: -24px;
  background: #fff; border-radius: 16px;
  padding: 16px 20px;
  box-shadow: 0 8px 40px rgba(0,0,0,.12);
  display: flex; align-items: center; gap: 12px;
  min-width: 220px;
}
.wfc-icon { width: 40px; height: 40px; border-radius: 10px; background: var(--teal-lt); display: flex; align-items: center; justify-content: center; color: var(--teal); font-size: 1.1rem; flex-shrink: 0; }
.wfc-val { font-family: var(--font-display); font-size: 1.3rem; font-weight: 800; color: var(--ink); }
.wfc-lbl { font-size: .72rem; color: var(--slate); }

.services-section { background: var(--ink); }
.services-section .section-title { color: #fff; }
.services-section .section-sub { color: rgba(255,255,255,.45); }
.services-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: 48px; }
.svc-card {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: 20px; padding: 28px 24px;
  transition: all .3s; position: relative; overflow: hidden;
  cursor: default;
}
.svc-card::before {
  content: ''; position: absolute; top: -60px; right: -60px;
  width: 140px; height: 140px; border-radius: 50%;
  background: radial-gradient(circle, rgba(13,148,136,.12), transparent 70%);
  transition: all .4s;
}
.svc-card:hover { background: rgba(255,255,255,.07); border-color: rgba(13,148,136,.3); transform: translateY(-4px); }
.svc-card:hover::before { transform: scale(1.5); }
.svc-icon { width: 44px; height: 44px; border-radius: 12px; background: rgba(13,148,136,.15); display: flex; align-items: center; justify-content: center; font-size: 1.05rem; color: #00c9a7; margin-bottom: 16px; }
.svc-cat { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: rgba(255,255,255,.3); margin-bottom: 6px; }
.svc-name { font-family: var(--font-display); font-size: 1rem; font-weight: 700; color: #fff; margin-bottom: 8px; }
.svc-desc { font-size: .78rem; color: rgba(255,255,255,.38); line-height: 1.6; margin-bottom: 14px; }
.svc-footer { display: flex; align-items: center; justify-content: space-between; }
.svc-price { font-family: var(--font-display); font-size: 1rem; font-weight: 700; color: #00c9a7; }
.svc-dur { font-size: .72rem; color: rgba(255,255,255,.3); }
.svc-cta { display: inline-flex; align-items: center; gap: 4px; font-size: .76rem; font-weight: 600; color: #00c9a7; opacity: 0; transition: opacity .2s; }
.svc-card:hover .svc-cta { opacity: 1; }
.svc-empty { text-align: center; padding: 48px; color: rgba(255,255,255,.3); font-size: .9rem; }

.how-section { background: var(--cream2); }
.steps-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-top: 48px; position: relative; }
.steps-grid::before {
  content: ''; position: absolute; top: 28px; left: 10%; right: 10%; height: 1px;
  background: repeating-linear-gradient(90deg, var(--border) 0, var(--border) 8px, transparent 8px, transparent 16px);
  z-index: 0;
}
.step-card { text-align: center; position: relative; z-index: 1; }
.step-num {
  width: 56px; height: 56px; border-radius: 50%;
  background: var(--ink); color: #fff;
  font-family: var(--font-display); font-size: 1.3rem; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 20px; border: 3px solid var(--cream2);
  box-shadow: 0 0 0 6px var(--cream2), 0 0 0 7px var(--border);
  transition: background .3s;
}
.step-card:hover .step-num { background: var(--teal); }
.step-title { font-family: var(--font-display); font-size: 1rem; font-weight: 700; margin-bottom: 8px; }
.step-body { font-size: .8rem; color: var(--slate); line-height: 1.6; }

.dentists-section { background: var(--cream); }
.dentists-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 48px; }
.doc-card {
  background: #fff; border: 1px solid var(--border);
  border-radius: 22px; overflow: hidden;
  transition: all .3s;
}
.doc-card:hover { border-color: var(--teal); transform: translateY(-4px); box-shadow: 0 12px 36px rgba(0,0,0,.08); }
.doc-img {
  height: 200px; background: var(--ink2);
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-display); font-size: 3.5rem; font-weight: 900;
  color: rgba(0,201,167,.25); position: relative; overflow: hidden;
}
.doc-img::after {
  content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 60px;
  background: linear-gradient(to top, var(--ink2), transparent);
}
.doc-body { padding: 20px; }
.doc-specialty { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 4px; }
.doc-name { font-family: var(--font-display); font-size: 1.05rem; font-weight: 800; margin-bottom: 8px; }
.doc-bio { font-size: .78rem; color: var(--slate); line-height: 1.6; margin-bottom: 14px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.doc-license { font-size: .72rem; color: var(--muted); display: flex; align-items: center; gap: 4px; }
.doc-book { display: flex; align-items: center; justify-content: space-between; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); }
.btn-book { display: inline-flex; align-items: center; gap: 5px; font-size: .76rem; font-weight: 700; color: var(--teal); background: var(--teal-lt); padding: 6px 14px; border-radius: 20px; transition: all .2s; }
.btn-book:hover { background: var(--teal); color: #fff; }
.doc-empty { text-align: center; padding: 48px; color: var(--muted); font-size: .9rem; }

.testimonials-section { background: var(--ink); overflow: hidden; }
.testimonials-section .section-title { color: #fff; }
.testimonials-section .section-sub { color: rgba(255,255,255,.4); }
.testi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-top: 48px; }
.testi-card {
  background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.07);
  border-radius: 20px; padding: 28px;
  transition: all .3s;
}
.testi-card:hover { background: rgba(255,255,255,.06); border-color: rgba(13,148,136,.25); }
.testi-stars { display: flex; gap: 3px; margin-bottom: 16px; }
.testi-star { color: #fbbf24; font-size: .9rem; }
.testi-quote { font-family: var(--font-display); font-size: 1rem; font-weight: 700; color: #fff; line-height: 1.65; margin-bottom: 20px; font-style: italic; }
.testi-quote::before { content: '\201C'; color: #00c9a7; font-size: 1.4rem; }
.testi-author { display: flex; align-items: center; gap: 12px; }
.testi-avatar { width: 38px; height: 38px; border-radius: 50%; background: var(--teal); display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 800; color: #fff; font-size: .9rem; flex-shrink: 0; }
.testi-name { font-size: .85rem; font-weight: 700; color: #fff; }
.testi-role { font-size: .72rem; color: rgba(255,255,255,.35); }

.cta-section {
  background: var(--cream);
  padding: 80px 5vw;
}
.cta-inner {
  max-width: 1200px; margin: 0 auto;
  background: var(--ink); border-radius: 32px;
  padding: 72px 64px;
  display: grid; grid-template-columns: 1fr auto; gap: 40px; align-items: center;
  position: relative; overflow: hidden;
}
.cta-inner::before {
  content: ''; position: absolute; top: -100px; right: -100px;
  width: 400px; height: 400px; border-radius: 50%;
  background: radial-gradient(circle, rgba(13,148,136,.2), transparent 70%);
}
.cta-inner::after {
  content: ''; position: absolute; bottom: -80px; left: 200px;
  width: 300px; height: 300px; border-radius: 50%;
  background: radial-gradient(circle, rgba(0,201,167,.07), transparent 70%);
}
.cta-text { position: relative; z-index: 1; }
.cta-eyebrow { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; color: #00c9a7; margin-bottom: 14px; }
.cta-title { font-family: var(--font-display); font-size: clamp(1.8rem, 3vw, 2.6rem); font-weight: 900; color: #fff; line-height: 1.15; margin-bottom: 12px; }
.cta-sub { font-size: .9rem; color: rgba(255,255,255,.45); line-height: 1.7; }
.cta-actions { display: flex; flex-direction: column; gap: 10px; align-items: center; position: relative; z-index: 1; flex-shrink: 0; }
.cta-actions .btn { padding: 13px 28px; font-size: .9rem; }
.cta-note { font-size: .72rem; color: rgba(255,255,255,.3); text-align: center; margin-top: 4px; }

.contact-section { background: var(--cream2); }
.contact-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: start; }
.contact-info { padding-top: 8px; }
.contact-items { display: flex; flex-direction: column; gap: 20px; margin: 32px 0; }
.ci { display: flex; gap: 14px; align-items: flex-start; }
.ci-icon { width: 42px; height: 42px; border-radius: 12px; background: var(--teal-lt); display: flex; align-items: center; justify-content: center; color: var(--teal); font-size: 1rem; flex-shrink: 0; }
.ci-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 3px; }
.ci-val { font-size: .88rem; font-weight: 600; color: var(--ink); }
.contact-form { background: #fff; border: 1px solid var(--border); border-radius: 24px; padding: 36px; }
.contact-form h3 { font-family: var(--font-display); font-size: 1.4rem; font-weight: 800; margin-bottom: 6px; }
.contact-form p { font-size: .84rem; color: var(--slate); margin-bottom: 28px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.field { margin-bottom: 16px; }
.field label { display: block; font-size: .77rem; font-weight: 600; color: var(--ink); margin-bottom: 6px; }
.field input, .field select, .field textarea {
  width: 100%; padding: 11px 14px;
  border: 1.5px solid var(--border); border-radius: 12px;
  font-size: .875rem; font-family: var(--font-body); color: var(--ink);
  background: var(--cream); outline: none;
  transition: border-color .2s, box-shadow .2s;
}
.field input:focus, .field select:focus, .field textarea:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13,148,136,.1); }
.field textarea { resize: vertical; min-height: 100px; }
.form-submit { width: 100%; padding: 13px; background: var(--teal); color: #fff; border: none; border-radius: 25px; font-size: .9rem; font-weight: 700; font-family: var(--font-body); cursor: pointer; transition: all .2s; }
.form-submit:hover { background: var(--teal2); transform: translateY(-1px); box-shadow: 0 4px 18px rgba(13,148,136,.3); }

.footer { background: var(--ink); padding: 64px 5vw 0; }
.footer-inner { max-width: 1200px; margin: 0 auto; }
.footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px; padding-bottom: 48px; border-bottom: 1px solid rgba(255,255,255,.07); }
.footer-brand { }
.footer-logo { display: flex; align-items: center; gap: 10px; font-family: var(--font-display); font-size: 1.2rem; font-weight: 700; color: #fff; margin-bottom: 14px; }
.footer-logo i { color: #00c9a7; }
.footer-tagline { font-size: .82rem; color: rgba(255,255,255,.38); line-height: 1.7; max-width: 260px; margin-bottom: 20px; }
.footer-socials { display: flex; gap: 10px; }
.social-btn { width: 36px; height: 36px; border-radius: 10px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.08); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.5); font-size: .9rem; transition: all .2s; }
.social-btn:hover { background: var(--teal); border-color: var(--teal); color: #fff; }
.footer-col h4 { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: rgba(255,255,255,.5); margin-bottom: 16px; }
.footer-col ul { list-style: none; display: flex; flex-direction: column; gap: 9px; }
.footer-col li a { font-size: .83rem; color: rgba(255,255,255,.38); transition: color .2s; }
.footer-col li a:hover { color: #fff; }
.footer-bottom { padding: 20px 0; display: flex; align-items: center; justify-content: space-between; }
.footer-copy { font-size: .78rem; color: rgba(255,255,255,.25); }
.footer-crafted { font-size: .78rem; color: rgba(255,255,255,.25); display: flex; align-items: center; gap: 5px; }
.footer-crafted i { color: #00c9a7; font-size: .7rem; }

.mobile-menu {
  display: none; position: fixed; inset: 0; background: var(--ink); z-index: 200;
  padding: 24px 5vw; flex-direction: column;
}
.mobile-menu.open { display: flex; }
.mm-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 40px; }
.mm-logo { font-family: var(--font-display); font-size: 1.2rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px; }
.mm-logo i { color: #00c9a7; }
.mm-close { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.08); color: #fff; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 1rem; }
.mm-nav { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.mm-nav a { font-family: var(--font-display); font-size: 1.6rem; font-weight: 700; color: rgba(255,255,255,.5); padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,.05); transition: color .2s; }
.mm-nav a:hover { color: #fff; }
.mm-actions { display: flex; flex-direction: column; gap: 10px; padding-top: 24px; }
.mm-actions .btn { justify-content: center; padding: 13px; font-size: .9rem; }

@media (max-width: 1024px) {
  .hero-inner { grid-template-columns: 1fr; }
  .hero-card-col { flex-direction: row; overflow-x: auto; padding-bottom: 8px; }
  .hero-card { min-width: 220px; }
  .why-grid { grid-template-columns: 1fr; }
  .why-visual { display: none; }
  .services-grid { grid-template-columns: repeat(2, 1fr); }
  .dentists-grid { grid-template-columns: repeat(2, 1fr); }
  .footer-grid { grid-template-columns: 1fr 1fr; }
  .cta-inner { grid-template-columns: 1fr; text-align: center; }
  .cta-actions { flex-direction: row; justify-content: center; }
}
@media (max-width: 768px) {
  .section { padding: 64px 5vw; }
  .nav-links, .nav-cta { display: none; }
  .nav-mobile-btn { display: flex; }
  .hero { padding: 100px 5vw 60px; }
  .hero-stats { gap: 20px; flex-wrap: wrap; }
  .services-grid { grid-template-columns: 1fr; }
  .steps-grid { grid-template-columns: 1fr 1fr; }
  .steps-grid::before { display: none; }
  .dentists-grid { grid-template-columns: 1fr; }
  .testi-grid { grid-template-columns: 1fr; }
  .contact-grid { grid-template-columns: 1fr; }
  .footer-grid { grid-template-columns: 1fr; }
  .footer-bottom { flex-direction: column; gap: 8px; }
  .cta-inner { padding: 40px 28px; border-radius: 24px; }
  .form-row { grid-template-columns: 1fr; }
  .testi-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<nav class="nav" id="nav">
  <div class="nav-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
  <div class="nav-links">
    <a href="#services">Services</a>
    <a href="#dentists">Our Dentists</a>
    <a href="#how-it-works">How It Works</a>
    <a href="#contact">Contact</a>
  </div>
  <div class="nav-cta">
    <a href="patient/login.php"    class="btn btn-outline-white">Sign In</a>
    <a href="patient/register.php" class="btn btn-teal"><i class="bi bi-calendar-plus"></i> Book Now</a>
  </div>
  <button class="nav-mobile-btn" onclick="toggleMenu()"><i class="bi bi-list"></i></button>
</nav>

<div class="mobile-menu" id="mobileMenu">
  <div class="mm-header">
    <div class="mm-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
    <button class="mm-close" onclick="toggleMenu()"><i class="bi bi-x-lg"></i></button>
  </div>
  <nav class="mm-nav">
    <a href="#services"    onclick="toggleMenu()">Services</a>
    <a href="#dentists"    onclick="toggleMenu()">Our Dentists</a>
    <a href="#how-it-works" onclick="toggleMenu()">How It Works</a>
    <a href="#contact"     onclick="toggleMenu()">Contact</a>
  </nav>
  <div class="mm-actions">
    <a href="patient/login.php"    class="btn btn-outline-white">Sign In</a>
    <a href="patient/register.php" class="btn btn-teal"><i class="bi bi-calendar-plus"></i> Book Appointment</a>
  </div>
</div>

<section class="hero" id="home">
  <div class="hero-bg">
    <div class="hero-blob-1"></div>
    <div class="hero-blob-2"></div>
    <div class="hero-grid-lines"></div>
  </div>

  <div class="hero-inner">
    <div>
      <div class="hero-badge"><i class="bi bi-patch-check-fill"></i> Certified Dental Specialists</div>
      <h1 class="hero-headline reveal">
        Your smile<br>deserves the<br><em>best care.</em>
      </h1>
      <p class="hero-sub reveal">Premium dental services in Imus — book online in minutes. From routine check-ups to full cosmetic work, our specialists have you covered.</p>
      <div class="hero-actions reveal">
        <a href="patient/register.php" class="btn btn-teal" style="padding:13px 28px;font-size:.92rem"><i class="bi bi-calendar-plus"></i> Book an Appointment</a>
        <a href="patient/book_appointment.php?guest=1" class="btn btn-outline-white" style="padding:13px 28px;font-size:.92rem"><i class="bi bi-person-walking"></i> Continue as Guest</a>
      </div>
      <div class="hero-stats reveal">
        <div>
          <div class="hstat-val"><?= $total_doctors ?>+</div>
          <div class="hstat-label">Specialists</div>
        </div>
        <div>
          <div class="hstat-val"><?= max($total_patients, 3) ?>+</div>
          <div class="hstat-label">Happy Patients</div>
        </div>
        <div>
          <div class="hstat-val"><?= max($total_appts, 3) ?>+</div>
          <div class="hstat-label">Treatments Done</div>
        </div>
        <div>
          <div class="hstat-val">5★</div>
          <div class="hstat-label">Patient Rating</div>
        </div>
      </div>
    </div>

    <div class="hero-card-col reveal-right">
      <div class="hero-card">
        <div class="hcard-top">
          <div class="hcard-icon"><i class="bi bi-calendar-check"></i></div>
          <div class="hcard-title">Online Booking</div>
        </div>
        <div class="hcard-body">Choose your dentist, pick your slot, confirm in under 2 minutes — any time, any device.</div>
      </div>
      <div class="hero-card">
        <div class="hcard-top">
          <div class="hcard-icon"><i class="bi bi-bell-fill"></i></div>
          <div class="hcard-title">Smart Reminders</div>
        </div>
        <div class="hcard-body">Email reminders 24 hours and 1 hour before your appointment so you never miss a visit.</div>
      </div>
      <div class="hero-card">
        <div class="hcard-top">
          <div class="hcard-icon"><i class="bi bi-stars"></i></div>
          <div class="hcard-title">Cosmetic Packages</div>
        </div>
        <div class="hcard-body">Whitening, veneers, braces — full cosmetic treatment plans tailored to you.</div>
        <?php if (!empty($services)):
          $whitening = null;
          foreach ($services as $s) { if (stripos($s['name'],'whiten') !== false) { $whitening = $s; break; } }
          if ($whitening): ?>
        <div class="hcard-price">From ₱<?= number_format($whitening['price']) ?></div>
        <?php endif; endif; ?>
      </div>
    </div>
  </div>

  <div class="hero-scroll">
    <div class="scroll-line"></div>
    <span>Scroll</span>
  </div>
</section>

<section class="section why-section">
  <div class="section-inner">
    <div class="why-grid">
      <div class="reveal-left">
        <span class="section-tag"><i class="bi bi-heart-fill"></i> Why Choose Us</span>
        <h2 class="section-title">Dental care you can<br>actually trust.</h2>
        <p class="section-sub">We combine clinical excellence with a warm, patient-first approach — making every visit as comfortable as it is effective.</p>
        <div class="why-features">
          <div class="why-feat">
            <div class="wf-icon"><i class="bi bi-patch-check-fill"></i></div>
            <div>
              <div class="wf-title">Licensed Specialists</div>
              <div class="wf-body">Every dentist carries a valid PRC license and undergoes continuous professional development.</div>
            </div>
          </div>
          <div class="why-feat">
            <div class="wf-icon"><i class="bi bi-clock-history"></i></div>
            <div>
              <div class="wf-title">Book Anytime, 24/7</div>
              <div class="wf-body">Our patient portal never closes. Schedule, reschedule or cancel from any device at any time.</div>
            </div>
          </div>
          <div class="why-feat">
            <div class="wf-icon"><i class="bi bi-shield-fill-check"></i></div>
            <div>
              <div class="wf-title">Sterilized & Safe</div>
              <div class="wf-body">Hospital-grade sterilization protocols and single-use disposables for every treatment.</div>
            </div>
          </div>
          <div class="why-feat">
            <div class="wf-icon"><i class="bi bi-chat-heart-fill"></i></div>
            <div>
              <div class="wf-title">Patient-First Care</div>
              <div class="wf-body">We take time to explain every procedure. No surprises — ever.</div>
            </div>
          </div>
        </div>
        <div style="margin-top:32px">
          <a href="patient/register.php" class="btn btn-ink"><i class="bi bi-arrow-right-circle"></i> Get Started Today</a>
        </div>
      </div>
      <div class="why-visual reveal-right">
        <div class="why-img-wrap">
          <div class="why-img-placeholder">
            <i class="bi bi-tooth-fill"></i>
          </div>
        </div>
        <div class="why-float-card">
          <div class="wfc-icon"><i class="bi bi-emoji-smile-fill"></i></div>
          <div>
            <div class="wfc-val"><?= max($total_patients, 3) ?>+ Patients</div>
            <div class="wfc-lbl">Trust Vytal Dental</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section services-section" id="services">
  <div class="section-inner">
    <div class="reveal">
      <span class="section-tag" style="background:rgba(0,201,167,.12);color:#00c9a7"><i class="bi bi-tooth"></i> Our Services</span>
      <h2 class="section-title" style="color:#fff">Everything your smile needs.</h2>
      <p class="section-sub" style="color:rgba(255,255,255,.45)">From preventive care to full smile makeovers — all bookable online in minutes.</p>
    </div>

    <?php if (!empty($services)): ?>
    <div class="services-grid">
      <?php foreach ($services as $i => $s):
        $icon = $cat_icons[$s['category'] ?? ''] ?? 'bi-tooth';
      ?>
      <div class="svc-card reveal" style="transition-delay:<?= $i * 60 ?>ms">
        <div class="svc-icon"><i class="bi <?= $icon ?>"></i></div>
        <?php if ($s['category']): ?><div class="svc-cat"><?= htmlspecialchars($s['category']) ?></div><?php endif; ?>
        <div class="svc-name"><?= htmlspecialchars($s['name']) ?></div>
        <?php if ($s['description']): ?>
        <div class="svc-desc"><?= htmlspecialchars($s['description']) ?></div>
        <?php endif; ?>
        <div class="svc-footer">
          <div>
            <?php if ($s['price']): ?><div class="svc-price">₱<?= number_format($s['price']) ?></div><?php endif; ?>
            <?php if ($s['duration_minutes']): ?><div class="svc-dur"><?= $s['duration_minutes'] ?> min session</div><?php endif; ?>
          </div>
          <a href="patient/book_appointment.php" class="svc-cta">Book <i class="bi bi-arrow-right"></i></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="svc-empty">Services coming soon.</div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:36px" class="reveal">
      <a href="patient/book_appointment.php" class="btn btn-teal" style="padding:13px 32px;font-size:.9rem">
        <i class="bi bi-calendar-plus"></i> Book Any Service Online
      </a>
    </div>
  </div>
</section>

<section class="section how-section" id="how-it-works">
  <div class="section-inner">
    <div class="reveal" style="text-align:center;max-width:540px;margin:0 auto">
      <span class="section-tag"><i class="bi bi-list-ol"></i> How It Works</span>
      <h2 class="section-title">Book your visit in<br>4 simple steps.</h2>
    </div>
    <div class="steps-grid">
      <?php
      $steps = [
        ['num'=>'01','icon'=>'bi-person-plus','title'=>'Create Account','body'=>'Register your patient profile in under a minute. Or skip it — book as a guest with just your name and email.'],
        ['num'=>'02','icon'=>'bi-tooth','title'=>'Choose a Service','body'=>'Pick from our full menu of dental treatments, with transparent prices and estimated duration.'],
        ['num'=>'03','icon'=>'bi-calendar-event','title'=>'Pick a Slot','body'=>'Choose your preferred dentist and select from real-time available time slots. No waiting on hold.'],
        ['num'=>'04','icon'=>'bi-check-circle-fill','title'=>'Confirm & Relax','body'=>'Get an instant email confirmation plus reminders 24h and 1h before your appointment.'],
      ];
      foreach ($steps as $i => $step): ?>
      <div class="step-card reveal" style="transition-delay:<?= $i * 100 ?>ms">
        <div class="step-num"><?= $step['num'] ?></div>
        <div class="step-title"><?= $step['title'] ?></div>
        <div class="step-body"><?= $step['body'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section dentists-section" id="dentists">
  <div class="section-inner">
    <div class="reveal">
      <span class="section-tag"><i class="bi bi-person-badge-fill"></i> Our Team</span>
      <h2 class="section-title">Meet your dentists.</h2>
      <p class="section-sub">PRC-licensed specialists with years of clinical experience — and a genuine commitment to gentle, effective care.</p>
    </div>

    <?php if (!empty($doctors)): ?>
    <div class="dentists-grid">
      <?php foreach ($doctors as $i => $d):
        $color = $specialties_color[$d['specialty']] ?? '#0d9488';
        $color = $specialties_color[$d['specialty']] ?? '#0d9488';
      ?>
      <div class="doc-card reveal" style="transition-delay:<?= $i * 80 ?>ms">
        <div class="doc-img" style="background:linear-gradient(135deg,#0f1117,#1e2330)">
          <?= strtoupper(substr($d['name'], strpos($d['name'],' ')+1, 1)) ?>
        </div>
        <div class="doc-body">
          <div class="doc-specialty" style="color:<?= $color ?>"><?= htmlspecialchars($d['specialty']) ?></div>
          <div class="doc-name"><?= htmlspecialchars($d['name']) ?></div>
          <?php if ($d['bio']): ?>
          <div class="doc-bio"><?= htmlspecialchars($d['bio']) ?></div>
          <?php else: ?>
          <div class="doc-bio">Dedicated to providing exceptional dental care with a gentle touch and evidence-based treatment approach.</div>
          <?php endif; ?>
          <?php if ($d['license_number']): ?>
          <div class="doc-license"><i class="bi bi-patch-check-fill" style="color:var(--teal)"></i> PRC License <?= htmlspecialchars($d['license_number']) ?></div>
          <?php endif; ?>
          <div class="doc-book">
            <a href="patient/book_appointment.php?doctor_id=<?= $d['id'] ?>" class="btn-book"><i class="bi bi-calendar-plus"></i> Book with Dr. <?= explode(' ', $d['name'])[count(explode(' ', $d['name']))-1] ?></a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="doc-empty">Our team profiles are coming soon.</div>
    <?php endif; ?>
  </div>
</section>


<section class="cta-section">
  <div class="cta-inner reveal">
    <div class="cta-text">
      <div class="cta-eyebrow"><i class="bi bi-lightning-fill"></i> Ready to get started?</div>
      <h2 class="cta-title">Your healthiest smile<br>starts today.</h2>
      <p class="cta-sub">New patients welcome. Book online in minutes — no phone call needed. Or walk in for emergency care.</p>
    </div>
    <div class="cta-actions">
      <a href="patient/register.php" class="btn btn-teal"><i class="bi bi-person-plus"></i> Create Account</a>
      <a href="patient/book_appointment.php?guest=1" class="btn btn-outline-white">Book as Guest</a>
      <div class="cta-note">No credit card required</div>
    </div>
  </div>
</section>

<section class="section contact-section" id="contact">
  <div class="section-inner">
    <div class="contact-grid">
      <div class="contact-info reveal-left">
        <span class="section-tag"><i class="bi bi-geo-alt-fill"></i> Contact Us</span>
        <h2 class="section-title">Visit us or<br>send a message.</h2>
        <p class="section-sub">Have a question? Send us a message and we'll get back to you within the day.</p>
        <div class="contact-items">
          <div class="ci">
            <div class="ci-icon"><i class="bi bi-geo-alt-fill"></i></div>
            <div><div class="ci-label">Address</div><div class="ci-val">Imus City, Cavite, Philippines</div></div>
          </div>
          <div class="ci">
            <div class="ci-icon"><i class="bi bi-telephone-fill"></i></div>
            <div><div class="ci-label">Phone</div><div class="ci-val">+63 912 345 6789</div></div>
          </div>
          <div class="ci">
            <div class="ci-icon"><i class="bi bi-envelope-fill"></i></div>
            <div><div class="ci-label">Email</div><div class="ci-val">hello@vytaldental.ph</div></div>
          </div>
          <div class="ci">
            <div class="ci-icon"><i class="bi bi-clock-fill"></i></div>
            <div><div class="ci-label">Hours</div><div class="ci-val">Mon–Sat: 9am – 6pm<br>Sun: By appointment only</div></div>
          </div>
        </div>
      </div>

      <div class="contact-form reveal-right">
        <h3>Send a Message</h3>
        <p>We'll get back to you within the same day.</p>
        <form action="mailto:hello@vytaldental.ph" method="GET">
          <div class="form-row">
            <div class="field">
              <label>First Name</label>
              <input type="text" placeholder="Juan">
            </div>
            <div class="field">
              <label>Last Name</label>
              <input type="text" placeholder="dela Cruz">
            </div>
          </div>
          <div class="field">
            <label>Email</label>
            <input type="email" placeholder="you@email.com">
          </div>
          <div class="field">
            <label>Inquiry Type</label>
            <select>
              <option value="">Select a topic</option>
              <option>Book an Appointment</option>
              <option>Service Inquiry</option>
              <option>Emergency Dental Care</option>
              <option>Billing & Payment</option>
              <option>Other</option>
            </select>
          </div>
          <div class="field">
            <label>Message</label>
            <textarea placeholder="How can we help you?"></textarea>
          </div>
          <button type="submit" class="form-submit"><i class="bi bi-send-fill"></i> Send Message</button>
        </form>
      </div>
    </div>
  </div>
</section>

<footer class="footer">
  <div class="footer-inner">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="footer-logo"><i class="bi bi-tooth-fill"></i> Vytal Dental</div>
        <p class="footer-tagline">Premium dental care in Imus — book online, get treated with excellence, leave smiling.</p>
      </div>
            <div class="footer-col">
        <h4>Admin Portal</h4>
        <ul>
          <li><a href="admin/login.php">Log in</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Patient Portal</h4>
        <ul>
          <li><a href="patient/register.php">Create Account</a></li>
          <li><a href="patient/login.php">Sign In</a></li>
          <li><a href="patient/book_appointment.php">Book Appointment</a></li>
          <li><a href="patient/appointments_calendar.php">View Calendar</a></li>
          <li><a href="patient/forgot_password.php">Reset Password</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Services</h4>
        <ul>
          <?php foreach (array_slice($services, 0, 5) as $s): ?>
          <li><a href="patient/book_appointment.php"><?= htmlspecialchars($s['name']) ?></a></li>
          <?php endforeach; ?>
          <?php if (empty($services)): ?>
          <li><a href="#">Dental Check-up</a></li>
          <li><a href="#">Teeth Whitening</a></li>
          <li><a href="#">Dental Braces</a></li>
          <li><a href="#">Tooth Extraction</a></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Clinic</h4>
        <ul>
          <li><a href="#dentists">Our Dentists</a></li>
          <li><a href="#how-it-works">How It Works</a></li>
          <li><a href="#contact">Contact Us</a></li>
          <li><a href="admin/login.php">Staff Login</a></li>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Service</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <div class="footer-copy">&copy; <?= date('Y') ?> Vytal Dental. All rights reserved.</div>
    </div>
  </div>
</footer>

<script>
const nav = document.getElementById('nav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('scrolled', window.scrollY > 40);
});

function toggleMenu() {
  document.getElementById('mobileMenu').classList.toggle('open');
}

const revealEls = document.querySelectorAll('.reveal, .reveal-left, .reveal-right');
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add('visible');
      observer.unobserve(e.target);
    }
  });
}, { threshold: 0.12 });
revealEls.forEach(el => observer.observe(el));

document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const target = document.querySelector(a.getAttribute('href'));
    if (target) {
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
});

function animateCounters() {
  document.querySelectorAll('.hstat-val').forEach(el => {
    const text = el.textContent;
    const num = parseInt(text.replace(/\D/g,''));
    const suffix = text.replace(/[\d]/g,'');
    if (!num) return;
    let start = 0;
    const end = num;
    const dur = 1600;
    const step = end / (dur / 16);
    const timer = setInterval(() => {
      start = Math.min(start + step, end);
      el.textContent = Math.floor(start).toLocaleString() + suffix;
      if (start >= end) clearInterval(timer);
    }, 16);
  });
}
const heroObserver = new IntersectionObserver((entries) => {
  if (entries[0].isIntersecting) { animateCounters(); heroObserver.disconnect(); }
}, { threshold: 0.3 });
heroObserver.observe(document.querySelector('.hero'));
</script>
</body>
</html>
#!/usr/bin/env python3
"""Genera landing pages de cursos agrícolas con header/footer de index.html."""

from pathlib import Path
from urllib.parse import quote

OUTPUT_DIR = Path(__file__).resolve().parent.parent / "public"

HEADER = """    <header class="header">
      <div class="container header-inner">
        <a class="brand" href="index.html" aria-label="DDG Del Valle - Cursos">
          <img
            src="https://ddgdelvalle.cl/wp-content/uploads/2026/03/LOGO-DDG_color.png"
            alt="Logo DDG Del Valle">
        </a>

        <nav class="nav" aria-label="Navegación">
          <a href="index.html#beneficios">Beneficios</a>
          <a href="index.html#cursos">Cursos</a>
          <a href="index.html#proceso">Proceso</a>
          <a href="index.html#faq">FAQ</a>
        </nav>

        <div class="header-right">
          <a class="btn btn-admin" href="portal-administrativo.html" target="_blank" rel="noopener" aria-label="Ir al portal administrativo">
            Portal administrativo
          </a>
          <a class="btn btn-primary" href="https://wa.me/56963163859?text=Hola%2C%20quiero%20consultar%20sobre%20los%20cursos%20DDG%20Del%20Valle" target="_blank" rel="noopener" aria-label="Contactar por WhatsApp">
            <img class="ico" src="https://ddgdelvalle.cl/wp-content/uploads/2026/03/icono_Online.png" alt="" width="18" height="18">
            Contáctanos
          </a>

          <button class="menu-btn" type="button" aria-label="Abrir menú" data-menu-btn>
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="container mobile-nav" data-mobile-nav>
        <a href="portal-administrativo.html" target="_blank" rel="noopener">Portal administrativo</a>
        <a href="index.html#beneficios">Beneficios</a>
        <a href="index.html#cursos">Cursos</a>
        <a href="index.html#proceso">Proceso</a>
        <a href="index.html#faq">FAQ</a>
      </div>
    </header>"""

FOOTER = """    <footer>
      <div class="container footer-inner">
        <div>
          <div style="display:flex;align-items:center;gap:10px;">
            <img src="https://ddgdelvalle.cl/wp-content/uploads/2026/03/icono-ddg.png" alt="" width="22" height="22" style="object-fit:contain;" loading="lazy">
            <strong style="color:#fff; font-family:Montserrat, Inter;">DDG Del Valle</strong>
          </div>
          <div class="small" style="margin-top:6px;">Curso y capacitación – Región de Coquimbo</div>
          <div class="small" style="margin-top:8px;">© 2026 DDG Del Valle. Todos los derechos reservados.</div>
        </div>

        <div>
          <div class="small">Para inscripciones y consultas:</div>
          <div class="small" style="margin-top:6px;">Teléfono / WhatsApp: <a href="tel:+56963163859" style="color:#fff; text-decoration: none; font-weight: bold;">+56 9 6316 3859</a></div>
          <div class="small">Email: <a href="mailto:contacto@ddgdelvalle.cl" style="color:#fff; text-decoration: none; font-weight: bold;">contacto@ddgdelvalle.cl</a></div>

          <div class="footer-links">
            <a href="#">Política de privacidad</a>
            <a href="#">Términos y condiciones</a>
          </div>
        </div>
      </div>
    </footer>"""

COURSE_CSS = """
    .ddg-page .course-hero{
      min-height:420px;
      background:
        linear-gradient(90deg, rgba(44,71,83,.96) 0%, rgba(44,71,83,.78) 55%, rgba(44,71,83,.35) 100%),
        radial-gradient(900px 520px at 12% 30%, rgba(213,204,60,.28), transparent 62%),
        url('https://ddgdelvalle.cl/wp-content/uploads/2026/03/Banner-DDG.jpg') center/cover no-repeat;
    }
    .ddg-page .course-hero .hero-content{ padding:56px 0 80px; max-width:760px; }
    .ddg-page .course-hero h1{ font-size:36px; }
    .ddg-page .course-meta{
      display:flex; flex-wrap:wrap; gap:10px; margin-top:16px;
    }
    .ddg-page .course-meta .badge{
      background:rgba(255,255,255,.14);
      border-color:rgba(255,255,255,.24);
      color:#fff;
    }
    .ddg-page .course-content-grid{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:24px;
      align-items:start;
    }
    .ddg-page .course-block{
      background:#fff;
      border:1px solid rgba(15,23,42,.10);
      border-radius:16px;
      padding:22px;
      box-shadow:0 14px 34px rgba(15,23,42,.06);
    }
    .ddg-page .course-block h2{
      margin:0 0 12px;
      font-family:Montserrat, Inter, system-ui;
      font-size:18px;
      color:var(--primary);
    }
    .ddg-page .course-block p{
      margin:0 0 12px;
      color:rgba(15,23,42,.72);
      font-size:13px;
      line-height:1.65;
    }
    .ddg-page .course-block p:last-child{ margin-bottom:0; }
    .ddg-page .course-list{
      margin:0; padding-left:18px;
      color:rgba(15,23,42,.72);
      font-size:13px;
      line-height:1.65;
    }
    .ddg-page .course-list li{ margin-bottom:6px; }
    .ddg-page .temario details{
      border:1px solid rgba(15,23,42,.10);
      border-radius:12px;
      margin-bottom:10px;
      background:#fff;
      overflow:hidden;
    }
    .ddg-page .temario summary{
      padding:12px 14px;
      cursor:pointer;
      font-weight:700;
      font-size:13px;
      color:var(--primary);
      list-style:none;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .ddg-page .temario summary::-webkit-details-marker{ display:none; }
    .ddg-page .temario summary::after{
      content:'+';
      width:26px; height:26px;
      border-radius:8px;
      display:flex; align-items:center; justify-content:center;
      background:rgba(44,71,83,.08);
      font-weight:900;
      flex-shrink:0;
    }
    .ddg-page .temario details[open] summary::after{ content:'–'; }
    .ddg-page .temario .mod-body{
      padding:0 14px 14px;
      border-top:1px solid rgba(15,23,42,.06);
    }
    .ddg-page .temario .mod-hours{
      font-size:11px;
      font-weight:600;
      color:var(--secondary);
      margin:10px 0 8px;
    }
    .ddg-page .temario ul{
      margin:0; padding-left:18px;
      font-size:12px;
      color:rgba(15,23,42,.72);
      line-height:1.6;
    }
    .ddg-page .temario li{ margin-bottom:4px; }
    .ddg-page .search-container{
      margin-top:22px;
      max-width:520px;
      margin-left:auto;
      margin-right:auto;
    }
    .ddg-page .search-container input{
      width:100%;
      padding:14px 18px;
      border-radius:999px;
      border:1px solid rgba(44,71,83,.18);
      background:#fff;
      font-family:Inter, system-ui, sans-serif;
      font-size:14px;
      color:var(--text);
      box-shadow:0 8px 24px rgba(15,23,42,.06);
      outline:none;
      transition:border-color .2s ease, box-shadow .2s ease;
    }
    .ddg-page .search-container input:focus{
      border-color:var(--primary);
      box-shadow:0 0 0 3px rgba(44,71,83,.12);
    }
    .ddg-page .search-container input::placeholder{ color:rgba(15,23,42,.45); }
    @media (max-width:980px){
      .ddg-page .course-content-grid{ grid-template-columns:1fr; }
      .ddg-page .course-hero h1{ font-size:28px; }
    }
"""

BASE_CSS_START = """<!doctype html>
<html lang="es-CL">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
  <meta name="theme-color" content="#2C4753"/>
  <title>{title_seo}</title>
  <meta name="description" content="{meta_desc}"/>
  <meta name="robots" content="index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1"/>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="icon" href="https://ddgdelvalle.cl/wp-content/uploads/2026/03/icono-ddg.png"/>

  <style>
    .ddg-page {{
      --primary:#2C4753; --secondary:#81A896; --bg:#F4F7F6; --bg2:#F2EEDB;
      --accent:#D5CC3C; --text:#0f172a; --muted:rgba(15,23,42,.72);
      margin:0; padding:0; width:100%;
      font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
      color:var(--text);
      background:linear-gradient(180deg, var(--bg) 0%, var(--bg2) 100%);
      line-height:1.6; -webkit-font-smoothing:antialiased;
      text-align:left; position:relative; overflow-x:hidden;
    }}
    .ddg-page * {{ box-sizing:border-box; }}
    .ddg-page a {{ color:inherit; text-decoration:none; }}
    .ddg-page img {{ max-width:100%; display:block; height:auto; }}
    .ddg-page .container{{ width:min(1120px, calc(100% - 48px)); margin:0 auto; position:relative; z-index:2; }}
    .ddg-page .header{{ position:sticky; top:0; z-index:999; background:rgba(255,255,255,.92); backdrop-filter:saturate(1.2) blur(10px); border-bottom:1px solid rgba(15,23,42,.08); }}
    .ddg-page .header-inner{{ display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 0; }}
    .ddg-page .brand{{ display:flex; align-items:center; gap:12px; min-width:220px; }}
    .ddg-page .brand img{{ height:60px; width:auto; object-fit:contain; margin:0; }}
    .ddg-page .nav{{ display:flex; align-items:center; gap:18px; font-size:13px; color:rgba(15,23,42,.72); margin:0; padding:0; }}
    .ddg-page .nav a{{ padding:8px 10px; border-radius:999px; transition:background .2s ease, color .2s ease; }}
    .ddg-page .nav a:hover{{ background:rgba(44,71,83,.08); color:var(--primary); }}
    .ddg-page .header-right{{ display:flex; align-items:center; gap:10px; }}
    .ddg-page .btn{{ display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:10px 14px; border-radius:999px; font-weight:700; font-family:Montserrat, Inter, system-ui; font-size:12px; border:1px solid transparent; transition:transform .08s ease, box-shadow .2s ease, background .2s ease; cursor:pointer; white-space:nowrap; }}
    .ddg-page .btn:active{{ transform:translateY(1px); }}
    .ddg-page .btn-primary{{ background:var(--primary); color:#fff; box-shadow:0 10px 22px rgba(44,71,83,.18); }}
    .ddg-page .btn-primary:hover{{ box-shadow:0 14px 30px rgba(44,71,83,.24); }}
    .ddg-page .btn-admin{{ background:#fff; color:var(--primary); border-color:rgba(44,71,83,.18); font-weight:800; }}
    .ddg-page .btn-admin:hover{{ background:rgba(44,71,83,.06); }}
    .ddg-page .btn-accent{{ background:var(--accent); color:#1b2b33; box-shadow:0 10px 22px rgba(213,204,60,.22); }}
    .ddg-page .btn-accent:hover{{ box-shadow:0 14px 30px rgba(213,204,60,.28); }}
    .ddg-page .btn-ghost{{ background:rgba(255,255,255,.12); color:#fff; border-color:rgba(255,255,255,.22); }}
    .ddg-page .btn-ghost:hover{{ background:rgba(255,255,255,.18); }}
    .ddg-page .btn .ico{{ width:18px; height:18px; object-fit:contain; display:block; margin:0; }}
    .ddg-page .menu-btn{{ display:none; width:42px; height:42px; border-radius:12px; border:1px solid rgba(15,23,42,.10); background:#fff; align-items:center; justify-content:center; cursor:pointer; padding:0; }}
    .ddg-page .menu-btn svg{{ width:20px; height:20px; }}
    .ddg-page .mobile-nav{{ display:none; padding:0 0 14px; }}
    .ddg-page .mobile-nav a{{ display:block; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.08); background:#fff; margin:10px 0 0; color:rgba(15,23,42,.78); font-weight:600; font-size:13px; }}
    .ddg-page .hero{{ position:relative; min-height:580px; display:flex; align-items:center; color:#fff; isolation:isolate; margin:0; padding:0; }}
    .ddg-page .hero::before{{ content:""; position:absolute; inset:0; background:linear-gradient(180deg, rgba(0,0,0,.05) 0%, rgba(0,0,0,.30) 100%); z-index:0; pointer-events:none; }}
    .ddg-page .hero .hero-content{{ position:relative; z-index:2; }}
    .ddg-page .kicker{{ display:inline-flex; align-items:center; gap:10px; padding:8px 12px; border-radius:999px; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.22); color:rgba(255,255,255,.92); font-weight:700; font-size:12px; font-family:Montserrat, Inter, system-ui; }}
    .ddg-page .kicker img{{ width:18px; height:18px; object-fit:contain; margin:0; }}
    .ddg-page .hero h1{{ margin:16px 0 10px; font-family:Montserrat, Inter, system-ui; line-height:1.1; letter-spacing:-.5px; color:#fff !important; text-shadow:0 14px 35px rgba(0,0,0,.35); }}
    .ddg-page .hero p{{ margin:0; max-width:720px; color:rgba(255,255,255,.88) !important; text-shadow:0 10px 26px rgba(0,0,0,.30); font-size:14px; }}
    .ddg-page .hero-actions{{ margin-top:18px; display:flex; gap:10px; flex-wrap:wrap; }}
    .ddg-page .section{{ padding:64px 0; scroll-margin-top:92px; }}
    .ddg-page .badge{{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; font-size:11px; font-weight:700; color:rgba(15,23,42,.72); background:rgba(44,71,83,.06); border:1px solid rgba(44,71,83,.12); }}
    .ddg-page footer{{ background:linear-gradient(180deg, #0b1220 0%, #070c14 100%); color:rgba(255,255,255,.82); padding:26px 0; border-top:1px solid rgba(255,255,255,.06); }}
    .ddg-page .footer-inner{{ display:flex; align-items:flex-start; justify-content:space-between; gap:18px; flex-wrap:wrap; }}
    .ddg-page .footer-links{{ display:flex; gap:14px; flex-wrap:wrap; margin-top:8px; }}
    .ddg-page .footer-links a{{ color:rgba(213,204,60,.92); text-decoration:underline; font-weight:700; font-size:11px; }}
    .ddg-page .small{{ font-size:11px; color:rgba(255,255,255,.70); }}
    @media (max-width:980px){{ .ddg-page .nav{{ display:none; }} .ddg-page .menu-btn{{ display:inline-flex; }} }}
    @media (max-width:520px){{ .ddg-page .container{{ width:calc(100% - 28px); }} .ddg-page .brand{{ min-width:auto; }} }}
"""

SCRIPT = """
  <script>
    (function(){
      const menuBtn = document.querySelector('[data-menu-btn]');
      const mobileNav = document.querySelector('[data-mobile-nav]');
      if(menuBtn && mobileNav){
        menuBtn.addEventListener('click', () => {
          const isOpen = mobileNav.style.display === 'block';
          mobileNav.style.display = isOpen ? 'none' : 'block';
        });
      }
    })();
  </script>
"""

COURSES = [
    {
        "slug": "curso-jardines-secos-xerojardineria-ovalle",
        "title": "Diseño e Implementación de Jardines Secos y Xerojardinería",
        "title_short": "Jardines Secos y Xerojardinería",
        "hours": "16 hrs",
        "hours_num": 16,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Riego-.webp",
        "desc_short": "Diseña e implementa jardines de bajo consumo hídrico con técnicas de xerojardinería y paisajismo sostenible.",
        "desc_long": "El curso entrega conocimientos teóricos y prácticos para el diseño, implementación y mantención de jardines secos, utilizando especies de bajo requerimiento hídrico y técnicas de paisajismo sostenible. Los participantes aprenderán a crear espacios ornamentales adaptados a zonas de escasez hídrica, optimizando el uso del agua y contribuyendo a la conservación de los recursos naturales.",
        "tagline": "Paisajismo sostenible con uso eficiente del agua en la Región de Coquimbo.",
        "dirigido": "Jardineros, emprendedores del rubro paisajístico, administradores de predios, funcionarios municipales y personas interesadas en el diseño de espacios verdes de bajo consumo hídrico.",
        "resultados": [
            "Comprender los principios de la xerojardinería.",
            "Diseñar jardines ornamentales de bajo consumo hídrico.",
            "Seleccionar especies adecuadas para climas secos.",
            "Implementar sistemas de riego eficientes.",
            "Mantener jardines sostenibles con bajos requerimientos de agua.",
        ],
        "modulos": [
            {"title": "Introducción a los Jardines Secos y la Xerojardinería", "hours": "4 horas", "topics": ["Conceptos de xerojardinería", "Beneficios ambientales y económicos", "Principios del paisajismo sostenible", "Adaptación al cambio climático y escasez hídrica", "Casos exitosos de jardines secos"]},
            {"title": "Diseño y Planificación de Jardines Secos", "hours": "4 horas", "topics": ["Evaluación del espacio y condiciones del terreno", "Principios básicos de diseño paisajístico", "Zonificación y distribución de especies", "Uso de elementos decorativos: piedras, gravillas y senderos", "Elaboración de planos básicos"]},
            {"title": "Selección de Especies y Sistemas de Riego", "hours": "4 horas", "topics": ["Plantas nativas y adaptadas a zonas áridas", "Suculentas, cactus y especies ornamentales resistentes", "Coberturas vegetales de bajo consumo hídrico", "Riego eficiente para jardines secos", "Mantención y cuidados básicos"]},
            {"title": "Taller Práctico de Implementación", "hours": "4 horas", "topics": ["Preparación del terreno", "Instalación de especies ornamentales", "Aplicación de mulch y cubiertas minerales", "Diseño práctico de un jardín seco", "Elaboración de un plan de mantención sostenible"]},
        ],
    },
    {
        "slug": "curso-cooperativismo-asociatividad-agricultura-ovalle",
        "title": "Cooperativismo y Asociatividad en la Agricultura",
        "title_short": "Cooperativismo y Asociatividad",
        "hours": "16 hrs",
        "hours_num": 16,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Apresto-Laboral-.webp",
        "desc_short": "Fortalece organizaciones rurales con principios cooperativos, comercialización conjunta y gestión asociativa.",
        "desc_long": "El curso entrega conocimientos sobre los principios del cooperativismo y la asociatividad como herramientas para fortalecer el desarrollo económico, social y productivo de agricultores y organizaciones rurales. Los participantes conocerán modelos de gestión cooperativa, mecanismos de comercialización conjunta, acceso a financiamiento y estrategias para mejorar la competitividad mediante el trabajo colaborativo.",
        "tagline": "Trabajo colaborativo y desarrollo productivo para el agro de Coquimbo.",
        "dirigido": "Agricultores, pequeños productores, usuarios de INDAP, líderes de organizaciones rurales, emprendedores agrícolas y personas interesadas en fortalecer la asociatividad en el sector agropecuario.",
        "resultados": [
            "Comprender los principios y beneficios del cooperativismo.",
            "Identificar oportunidades de asociatividad para fortalecer actividades productivas.",
            "Participar en procesos de gestión y toma de decisiones en organizaciones cooperativas.",
            "Desarrollar estrategias de comercialización conjunta.",
            "Formular iniciativas que promuevan el desarrollo económico y social de las comunidades.",
        ],
        "modulos": [
            {"title": "Fundamentos del Cooperativismo y la Asociatividad", "hours": "4 horas", "topics": ["Historia y evolución del cooperativismo", "Principios y valores cooperativos", "Beneficios de la asociatividad en el sector agrícola", "Tipos de organizaciones asociativas", "Marco normativo de las cooperativas en Chile"]},
            {"title": "Organización y Gestión de Cooperativas Agrícolas", "hours": "4 horas", "topics": ["Estructura organizacional de una cooperativa", "Roles y responsabilidades de los socios", "Gobernanza y toma de decisiones", "Planificación estratégica", "Administración y gestión de recursos"]},
            {"title": "Comercialización y Desarrollo de Negocios Asociativos", "hours": "4 horas", "topics": ["Comercialización conjunta de productos agrícolas", "Encadenamientos productivos y mercados", "Valor agregado y diferenciación de productos", "Marketing y posicionamiento comercial", "Acceso a financiamiento y programas de apoyo"]},
            {"title": "Elaboración de Proyectos Asociativos", "hours": "4 horas", "topics": ["Identificación de oportunidades de colaboración", "Diseño de iniciativas cooperativas", "Formulación de planes de trabajo", "Resolución de conflictos y trabajo en equipo", "Presentación de proyectos asociativos"]},
        ],
    },
    {
        "slug": "curso-agricultura-organica-agroecologica-ovalle",
        "title": "Agricultura Orgánica y Producción Agroecológica",
        "title_short": "Agricultura Orgánica y Agroecológica",
        "hours": "16 hrs",
        "hours_num": 16,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Plaguicida-.webp",
        "desc_short": "Principios y técnicas de producción orgánica: suelos, fertilización natural y control ecológico de plagas.",
        "desc_long": "El curso entrega conocimientos teóricos y prácticos sobre los principios y técnicas de la agricultura orgánica, promoviendo sistemas de producción sostenibles que respeten el medio ambiente, la biodiversidad y la salud de las personas. Los participantes aprenderán estrategias para el manejo ecológico de cultivos, conservación de suelos, fertilización orgánica y control natural de plagas y enfermedades.",
        "tagline": "Producción de alimentos sanos con enfoque agroecológico en Ovalle.",
        "dirigido": "Agricultores, usuarios de INDAP, pequeños productores, emprendedores rurales, técnicos agrícolas y personas interesadas en la producción orgánica y agroecológica.",
        "resultados": [
            "Comprender los principios de la agricultura orgánica.",
            "Implementar prácticas sostenibles de manejo de suelo y cultivos.",
            "Elaborar y utilizar fertilizantes orgánicos.",
            "Aplicar métodos ecológicos de prevención y control de plagas.",
            "Desarrollar sistemas productivos más eficientes y amigables con el medio ambiente.",
        ],
        "modulos": [
            {"title": "Fundamentos de la Agricultura Orgánica", "hours": "4 horas", "topics": ["Conceptos y principios de la agricultura orgánica", "Diferencias entre agricultura convencional, orgánica y agroecológica", "Beneficios ambientales, económicos y sociales", "Normativa y certificación orgánica", "Sustentabilidad y biodiversidad agrícola"]},
            {"title": "Manejo y Conservación del Suelo", "hours": "4 horas", "topics": ["Características de un suelo fértil", "Materia orgánica y vida del suelo", "Elaboración y uso de compost", "Abonos verdes y cobertura vegetal", "Conservación y recuperación de suelos agrícolas"]},
            {"title": "Producción Orgánica de Cultivos", "hours": "4 horas", "topics": ["Planificación de cultivos", "Producción de almácigos", "Siembra y trasplante", "Asociaciones y rotaciones de cultivos", "Manejo eficiente del agua", "Fertilización orgánica"]},
            {"title": "Control Ecológico de Plagas y Enfermedades", "hours": "4 horas", "topics": ["Principales plagas y enfermedades agrícolas", "Prevención y monitoreo", "Control biológico", "Elaboración de biopreparados y extractos vegetales", "Buenas prácticas agrícolas y manejo integrado"]},
        ],
    },
    {
        "slug": "curso-produccion-agricola-invernaderos-ovalle",
        "title": "Manejo y Producción Agrícola en Invernaderos",
        "title_short": "Producción Agrícola en Invernaderos",
        "hours": "32 hrs",
        "hours_num": 32,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Riego-.webp",
        "desc_short": "Diseño, operación y manejo de invernaderos para producción hortícola, medicinal y de almácigos durante todo el año.",
        "desc_long": "El curso entrega conocimientos teóricos y prácticos para el diseño, implementación y manejo de invernaderos destinados a la producción hortícola, medicinal y de almácigos. Los participantes aprenderán técnicas de control ambiental, riego, nutrición vegetal, manejo fitosanitario y planificación productiva, permitiendo optimizar la producción durante todo el año y mejorar la calidad de los cultivos.",
        "tagline": "Producción protegida de alto rendimiento para el valle de Ovalle.",
        "dirigido": "Agricultores, usuarios de INDAP, pequeños productores, emprendedores agrícolas, administradores de predios, técnicos agrícolas, estudiantes del área agropecuaria y personas interesadas en la producción intensiva bajo invernadero.",
        "resultados": [
            "Operar y mantener adecuadamente un invernadero agrícola.",
            "Controlar variables ambientales que influyen en la producción.",
            "Implementar programas de riego y nutrición eficientes.",
            "Aplicar medidas preventivas para el control sanitario.",
            "Planificar y gestionar la producción de cultivos protegidos.",
        ],
        "modulos": [
            {"title": "Fundamentos de la Producción en Invernaderos", "hours": "8 horas", "topics": ["Conceptos de agricultura protegida", "Tipos de invernaderos y estructuras", "Ventajas y limitaciones de la producción bajo cubierta", "Materiales de construcción", "Diseño y ubicación del invernadero", "Seguridad y buenas prácticas agrícolas"]},
            {"title": "Manejo Ambiental y Operación del Invernadero", "hours": "8 horas", "topics": ["Temperatura y humedad relativa", "Ventilación natural y mecánica", "Radiación solar y sombreado", "Control climático", "Monitoreo de variables ambientales", "Uso eficiente del agua y energía"]},
            {"title": "Manejo Agronómico de los Cultivos", "hours": "8 horas", "topics": ["Producción de almácigos", "Preparación de sustratos", "Siembra y trasplante", "Riego y fertirrigación", "Nutrición vegetal", "Manejo integrado de plagas y enfermedades", "Polinización y manejo de cultivos"]},
            {"title": "Taller Práctico de Producción Protegida", "hours": "8 horas", "topics": ["Evaluación de infraestructura", "Instalación y mantención de sistemas de riego", "Manejo de cultivos en invernadero", "Diagnóstico de problemas productivos", "Elaboración de un plan de producción", "Cosecha y manejo postcosecha"]},
        ],
    },
    {
        "slug": "curso-fruticultura-sostenible-huertos-frutales-ovalle",
        "title": "Fruticultura Sostenible y Manejo de Huertos Frutales",
        "title_short": "Fruticultura Sostenible",
        "hours": "32 hrs",
        "hours_num": 32,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Plaguicida-.webp",
        "desc_short": "Establecimiento y manejo de huertos frutales: poda, riego, nutrición, sanidad y cosecha con enfoque sostenible.",
        "desc_long": "El curso entrega conocimientos teóricos y prácticos para el establecimiento, manejo y desarrollo de huertos frutales, considerando aspectos técnicos, productivos y ambientales. Los participantes aprenderán sobre selección de especies, preparación de suelo, plantación, riego, nutrición, poda, control de plagas y cosecha, con el objetivo de mejorar la productividad y calidad de la producción frutícola.",
        "tagline": "Huertos frutales productivos y rentables para la Región de Coquimbo.",
        "dirigido": "Agricultores, usuarios de INDAP, pequeños productores, emprendedores rurales, técnicos agrícolas, estudiantes del área silvoagropecuaria y personas interesadas en la producción frutícola sostenible.",
        "resultados": [
            "Planificar y establecer huertos frutales.",
            "Aplicar técnicas de manejo agronómico.",
            "Mejorar la calidad y productividad de los frutales.",
            "Implementar estrategias de manejo sostenible.",
            "Tomar decisiones técnicas para optimizar la producción.",
        ],
        "modulos": [
            {"title": "Fundamentos de la Fruticultura", "hours": "8 horas", "topics": ["Importancia económica de la fruticultura", "Principales especies frutales de Chile", "Requerimientos climáticos y edáficos", "Selección de variedades y portainjertos", "Planificación de un proyecto frutícola"]},
            {"title": "Establecimiento y Manejo del Huerto Frutal", "hours": "8 horas", "topics": ["Preparación de suelo", "Diseño y marco de plantación", "Plantación de árboles frutales", "Sistemas de conducción", "Manejo del riego en frutales"]},
            {"title": "Nutrición, Poda y Manejo Fitosanitario", "hours": "8 horas", "topics": ["Nutrición vegetal en frutales", "Fertilización y fertirrigación", "Poda de formación, producción y renovación", "Principales plagas y enfermedades", "Manejo integrado y control sostenible"]},
            {"title": "Cosecha, Postcosecha y Taller Práctico", "hours": "8 horas", "topics": ["Determinación del momento de cosecha", "Técnicas de cosecha", "Manejo de postcosecha", "Calidad e inocuidad alimentaria", "Evaluación práctica de huertos frutales", "Elaboración de un plan de manejo productivo"]},
        ],
    },
    {
        "slug": "curso-operacion-mantenimiento-maquinaria-agricola-ovalle",
        "title": "Operación y Mantención de Maquinaria Agrícola",
        "title_short": "Operación y Mantención de Maquinaria Agrícola",
        "hours": "16 hrs",
        "hours_num": 16,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Mantencion-Maquinaria-.webp",
        "desc_short": "Operación segura, inspección y mantención preventiva de tractores e implementos agrícolas.",
        "desc_long": "El curso entrega conocimientos teóricos y prácticos para la operación segura, inspección y mantención preventiva de maquinaria agrícola utilizada en labores de preparación de suelo, siembra, manejo de cultivos y cosecha. Los participantes aprenderán a identificar componentes mecánicos básicos, aplicar procedimientos de mantenimiento y operar equipos de manera eficiente.",
        "tagline": "Seguridad operacional y continuidad productiva en el campo de Coquimbo.",
        "dirigido": "Agricultores, operadores de maquinaria agrícola, trabajadores del sector silvoagropecuario, usuarios de INDAP, estudiantes del área agrícola, encargados de predios y personas interesadas en mecanización agrícola.",
        "resultados": [
            "Operar maquinaria agrícola de forma segura y eficiente.",
            "Identificar los principales componentes de los equipos agrícolas.",
            "Ejecutar labores de mantención preventiva.",
            "Detectar fallas básicas y aplicar medidas correctivas iniciales.",
            "Reducir riesgos operacionales y costos asociados a la mantención.",
        ],
        "modulos": [
            {"title": "Introducción a la Maquinaria Agrícola y Seguridad Operacional", "hours": "4 horas", "topics": ["Tipos y usos de la maquinaria agrícola", "Componentes principales de tractores e implementos", "Normativa de seguridad aplicable", "Identificación de riesgos operacionales", "Uso correcto de EPP", "Inspección previa a la operación"]},
            {"title": "Operación de Maquinaria Agrícola", "hours": "4 horas", "topics": ["Principios básicos de funcionamiento", "Operación segura de tractores agrícolas", "Uso de implementos agrícolas", "Maniobras en terreno", "Procedimientos de encendido, traslado y detención", "Buenas prácticas de operación eficiente"]},
            {"title": "Mantención Preventiva y Diagnóstico Básico", "hours": "4 horas", "topics": ["Mantención diaria, semanal y periódica", "Sistemas de lubricación", "Revisión de filtros, correas y baterías", "Sistema hidráulico básico", "Neumáticos y sistemas de rodado", "Identificación de fallas frecuentes"]},
            {"title": "Taller Práctico de Operación y Mantención", "hours": "4 horas", "topics": ["Inspección de maquinaria agrícola", "Aplicación de pautas de mantenimiento preventivo", "Operación práctica de equipos", "Detección de fallas comunes", "Elaboración de un plan de mantención"]},
        ],
    },
    {
        "slug": "curso-humus-lombricultura-biopreparados-ovalle",
        "title": "Producción de Humus, Lombricultura y Biopreparados Agrícolas",
        "title_short": "Humus, Lombricultura y Biopreparados",
        "hours": "16 hrs",
        "hours_num": 16,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Plaguicida-.webp",
        "desc_short": "Produce humus de lombriz y biopreparados agroecológicos para mejorar suelos y fortalecer cultivos.",
        "desc_long": "El curso entrega conocimientos teóricos y prácticos sobre la producción de humus de lombriz, manejo de sistemas de lombricultura y elaboración de biopreparados para la nutrición y protección de cultivos. Los participantes aprenderán técnicas sostenibles para transformar residuos orgánicos en fertilizantes naturales y elaborar insumos agroecológicos.",
        "tagline": "Economía circular y fertilización orgánica para el agro de Ovalle.",
        "dirigido": "Agricultores, usuarios de INDAP, productores agroecológicos, emprendedores rurales, establecimientos educacionales, organizaciones comunitarias y personas interesadas en la producción orgánica y el reciclaje de residuos orgánicos.",
        "resultados": [
            "Implementar sistemas de lombricultura a pequeña y mediana escala.",
            "Producir humus de lombriz de calidad.",
            "Elaborar biopreparados para mejorar la fertilidad y vigor de los cultivos.",
            "Reducir costos mediante el aprovechamiento de residuos orgánicos.",
            "Aplicar prácticas agroecológicas que favorezcan la sostenibilidad productiva.",
        ],
        "modulos": [
            {"title": "Introducción a la Lombricultura y Economía Circular", "hours": "4 horas", "topics": ["Principios de la agricultura sostenible", "Importancia de la materia orgánica", "Beneficios de la lombricultura", "Biología y manejo de la lombriz roja californiana", "Valorización de residuos orgánicos"]},
            {"title": "Producción de Humus de Lombriz", "hours": "4 horas", "topics": ["Diseño e instalación de lombriceras", "Alimentación y manejo de lombrices", "Control de humedad y temperatura", "Cosecha de humus sólido y líquido", "Almacenamiento y aplicación en cultivos"]},
            {"title": "Elaboración de Biopreparados Agroecológicos", "hours": "4 horas", "topics": ["Principios de los biopreparados", "Biofertilizantes líquidos", "Té de compost y humus", "Extractos vegetales para nutrición vegetal", "Preparados naturales para fortalecer cultivos"]},
            {"title": "Taller Práctico de Producción y Aplicación", "hours": "4 horas", "topics": ["Construcción de una lombricera", "Elaboración de humus sólido y lixiviados", "Preparación de biopreparados", "Aplicación en huertos y cultivos", "Elaboración de un plan de producción agroecológica"]},
        ],
    },
    {
        "slug": "curso-mejoramiento-recuperacion-suelos-agricolas-ovalle",
        "title": "Mejoramiento y Recuperación de Suelos Agrícolas",
        "title_short": "Mejoramiento y Recuperación de Suelos",
        "hours": "16 hrs",
        "hours_num": 16,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Riego-.webp",
        "desc_short": "Diagnóstico, conservación y recuperación de suelos agrícolas para aumentar fertilidad y productividad.",
        "desc_long": "El curso entrega conocimientos teóricos y prácticos sobre el diagnóstico, mejoramiento y recuperación de suelos agrícolas, promoviendo prácticas sostenibles que permitan aumentar la fertilidad, mejorar la estructura del suelo y optimizar la productividad de los cultivos. Los participantes aprenderán técnicas de manejo de materia orgánica, conservación de suelos y estrategias para enfrentar erosión, compactación y salinidad.",
        "tagline": "Suelos fértiles y productivos para la agricultura del valle de Ovalle.",
        "dirigido": "Agricultores, usuarios de INDAP, pequeños productores, técnicos agrícolas, profesionales del sector silvoagropecuario, estudiantes y personas interesadas en la gestión sostenible de los recursos naturales.",
        "resultados": [
            "Identificar problemas que afectan la calidad y fertilidad del suelo.",
            "Aplicar técnicas de conservación y recuperación de suelos agrícolas.",
            "Elaborar y utilizar enmiendas orgánicas para mejorar la productividad.",
            "Reducir procesos de erosión y degradación.",
            "Diseñar estrategias sostenibles para el manejo de suelo y agua.",
        ],
        "modulos": [
            {"title": "Fundamentos del Suelo y Diagnóstico de Problemas", "hours": "4 horas", "topics": ["Formación y características del suelo", "Propiedades físicas, químicas y biológicas", "Interpretación básica de análisis de suelo", "Identificación de problemas de degradación", "Diagnóstico de compactación, erosión y salinidad"]},
            {"title": "Fertilidad y Manejo de la Materia Orgánica", "hours": "4 horas", "topics": ["Importancia de la materia orgánica", "Compostaje y vermicompostaje", "Abonos orgánicos y biofertilizantes", "Actividad biológica del suelo", "Estrategias para aumentar la fertilidad"]},
            {"title": "Técnicas de Conservación y Recuperación de Suelos", "hours": "4 horas", "topics": ["Control de erosión hídrica y eólica", "Coberturas vegetales y cultivos de cobertura", "Labranza de conservación", "Manejo de pendientes y terrazas", "Recuperación de suelos degradados"]},
            {"title": "Taller Práctico de Mejoramiento de Suelos", "hours": "4 horas", "topics": ["Evaluación de la calidad del suelo", "Elaboración de compost y enmiendas orgánicas", "Aplicación de técnicas de conservación", "Diseño de un plan de mejoramiento de suelo", "Análisis de casos prácticos locales"]},
        ],
    },
    {
        "slug": "curso-jardineria-mantenimiento-areas-verdes-ovalle",
        "title": "Jardinería y Mantención de Áreas Verdes",
        "title_short": "Jardinería y Áreas Verdes",
        "hours": "16 hrs",
        "hours_num": 16,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Riego-.webp",
        "desc_short": "Diseño, establecimiento y mantención de jardines y áreas verdes con técnicas profesionales y sostenibles.",
        "desc_long": "El curso entrega conocimientos teóricos y prácticos para el diseño, establecimiento y mantención de jardines y áreas verdes, promoviendo el uso eficiente de los recursos naturales y la conservación del entorno. Los participantes aprenderán técnicas de preparación de suelo, selección de especies ornamentales, riego, fertilización, poda y control de plagas.",
        "tagline": "Espacios verdes funcionales y estéticos para Ovalle y la Región de Coquimbo.",
        "dirigido": "Trabajadores de áreas verdes, funcionarios municipales, jardineros, emprendedores del rubro paisajístico, comunidades, establecimientos educacionales y personas interesadas en la creación y mantención de jardines.",
        "resultados": [
            "Diseñar y planificar jardines de acuerdo con las características del entorno.",
            "Preparar adecuadamente el suelo para el establecimiento de especies ornamentales.",
            "Aplicar técnicas de riego, fertilización y poda.",
            "Mantener áreas verdes en óptimas condiciones sanitarias y estéticas.",
            "Utilizar herramientas y equipos de jardinería de manera segura y eficiente.",
        ],
        "modulos": [
            {"title": "Fundamentos de la Jardinería y Diseño de Espacios Verdes", "hours": "4 horas", "topics": ["Conceptos básicos de jardinería", "Tipos de jardines y áreas verdes", "Principios de diseño paisajístico", "Selección de especies ornamentales", "Adaptación de especies al clima y condiciones locales"]},
            {"title": "Preparación del Suelo y Establecimiento de Jardines", "hours": "4 horas", "topics": ["Características y mejoramiento del suelo", "Preparación de terrenos para plantación", "Producción y trasplante de especies ornamentales", "Uso de compost y fertilizantes", "Técnicas de plantación"]},
            {"title": "Mantención de Jardines y Áreas Verdes", "hours": "4 horas", "topics": ["Riego eficiente", "Poda de formación y mantención", "Fertilización de especies ornamentales", "Control integrado de plagas y enfermedades", "Manejo de césped y cubresuelos"]},
            {"title": "Taller Práctico de Jardinería", "hours": "4 horas", "topics": ["Identificación de especies ornamentales", "Uso seguro de herramientas de jardinería", "Diseño básico de un jardín", "Ejecución de labores de plantación y poda", "Elaboración de un plan de mantención de áreas verdes"]},
        ],
    },
    {
        "slug": "curso-topografia-aplicada-agricultura-ovalle",
        "title": "Topografía Aplicada a la Agricultura",
        "title_short": "Topografía Aplicada a la Agricultura",
        "hours": "16 hrs",
        "hours_num": 16,
        "price": "Presencial · Ovalle",
        "image": "https://ddgdelvalle.cl/wp-content/uploads/2026/04/Riego-.webp",
        "desc_short": "Medición, levantamiento topográfico y planificación de predios para riego, drenaje y conservación de suelos.",
        "desc_long": "El curso entrega conocimientos teóricos y prácticos sobre los principios básicos de la topografía aplicados al sector agrícola, permitiendo interpretar las características del terreno para optimizar el diseño de sistemas de riego, drenaje, plantaciones, caminos interiores y obras de conservación de suelo y agua.",
        "tagline": "Planificación eficiente de predios agrícolas en la Región de Coquimbo.",
        "dirigido": "Agricultores, pequeños productores, usuarios de INDAP, técnicos agrícolas, encargados de predios, estudiantes del área silvoagropecuaria y personas interesadas en mejorar la gestión y planificación de terrenos agrícolas.",
        "resultados": [
            "Interpretar información topográfica aplicada a la agricultura.",
            "Realizar mediciones básicas de terrenos agrícolas.",
            "Identificar pendientes y características del relieve.",
            "Apoyar el diseño de sistemas de riego y drenaje.",
            "Contribuir a una mejor planificación y uso eficiente del predio agrícola.",
        ],
        "modulos": [
            {"title": "Fundamentos de la Topografía Agrícola", "hours": "4 horas", "topics": ["Conceptos básicos de topografía", "Importancia de la topografía en la agricultura", "Sistemas de coordenadas y orientación", "Interpretación de planos y mapas topográficos", "Curvas de nivel y pendientes"]},
            {"title": "Instrumentos y Técnicas de Medición", "hours": "4 horas", "topics": ["Uso de cinta métrica, nivel y brújula", "Introducción al nivel topográfico", "Uso de GPS en agricultura", "Levantamientos topográficos básicos", "Registro y procesamiento de datos"]},
            {"title": "Aplicaciones de la Topografía en Predios Agrícolas", "hours": "4 horas", "topics": ["Diseño de sistemas de riego gravitacional y tecnificado", "Planificación de drenajes agrícolas", "Trazado de caminos y accesos", "Conservación de suelos y control de erosión", "Diseño de terrazas y obras de conservación hídrica"]},
            {"title": "Taller Práctico de Levantamiento Topográfico", "hours": "4 horas", "topics": ["Medición de pendientes en terreno", "Elaboración de croquis y planos básicos", "Determinación de desniveles", "Aplicación práctica en diseño agrícola", "Interpretación de resultados y toma de decisiones"]},
        ],
    },
]


def modulos_html(modulos):
    parts = ['<div class="temario">']
    for i, m in enumerate(modulos):
        open_attr = " open" if i == 0 else ""
        topics = "".join(f"<li>{t}</li>" for t in m["topics"])
        parts.append(f"""            <details{open_attr}>
              <summary>{m['title']}</summary>
              <div class="mod-body">
                <p class="mod-hours">Duración: {m['hours']}</p>
                <ul>{topics}</ul>
              </div>
            </details>""")
    parts.append("          </div>")
    return "\n".join(parts)


def resultados_html(resultados):
    items = "".join(f"<li>{r}</li>" for r in resultados)
    return f'<ul class="course-list">{items}</ul>'


def generate_page(course):
    wa_text = f"Hola, quiero inscribirme en el curso de {course['title']} en Ovalle"
    wa_url = "https://wa.me/56963163859?text=" + quote(wa_text)
    title_seo = f"Curso de {course['title_short']} en Ovalle | DDG Del Valle"
    meta_desc = f"Curso de {course['title_short']} en Ovalle, Región de Coquimbo. {course['hours']} presenciales. {course['desc_short']} Inscríbete en DDG Del Valle."

    return f"""{BASE_CSS_START.format(title_seo=title_seo, meta_desc=meta_desc)}
{COURSE_CSS}
  </style>
</head>
<body>
  <div class="ddg-page">
{HEADER}

    <main id="top">
      <section class="hero course-hero" aria-label="{course['title']}">
        <div class="container">
          <div class="hero-content">
            <div class="kicker">
              <img src="https://ddgdelvalle.cl/wp-content/uploads/2026/03/Icono_cursos.png" alt="" width="18" height="18">
              Curso presencial · Ovalle
            </div>
            <h1>{course['title']}</h1>
            <p>{course['tagline']}</p>
            <div class="course-meta">
              <span class="badge">Disponible</span>
              <span class="badge">{course['hours']}</span>
              <span class="badge">Región de Coquimbo</span>
            </div>
            <div class="hero-actions">
              <a class="btn btn-accent" href="{wa_url}" target="_blank" rel="noopener">Inscribirme por WhatsApp</a>
              <a class="btn btn-ghost" href="index.html#cursos">Ver todos los cursos</a>
            </div>
          </div>
        </div>
      </section>

      <section class="section" aria-label="Contenido del curso">
        <div class="container">
          <div class="course-content-grid">
            <div class="course-col-a">
              <article class="course-block">
                <h2>Descripción del curso</h2>
                <p>{course['desc_long']}</p>
              </article>
              <article class="course-block" style="margin-top:16px;">
                <h2>Resultados esperados</h2>
                {resultados_html(course['resultados'])}
              </article>
            </div>
            <div class="course-col-b">
              <article class="course-block">
                <h2>Temario / Módulos</h2>
{modulos_html(course['modulos'])}
              </article>
            </div>
          </div>
        </div>
      </section>
    </main>

{FOOTER}
  </div>
{SCRIPT}
</body>
</html>
"""


def card_html(course, delay):
    slug = course["slug"] + ".html"
    alt = course["title_short"]
    return f"""            <article class="curso-card reveal-up" data-categoria="{course.get('categoria', 'agro')}" data-estado="disponible" data-delay="{delay}">
              <a class="curso-media" href="{slug}" aria-label="Ver curso {alt}">
                <img src="{course['image']}" alt="{alt}" loading="lazy">
              </a>
              <div class="curso-card-body">
                <h3><a href="{slug}" style="color:inherit;text-decoration:none;">{course['title_short']}</a></h3>
                <p>{course['desc_short']}</p>
                <div class="badges">
                  <span class="badge" style="background:var(--primary); color:#fff; border-color:var(--primary);">Disponible</span>
                  <span class="badge">{course['hours']}</span>
                </div>
                <div class="price">{course['price']}</div>
                <a class="btn btn-info" href="{slug}">Ver curso y cupos</a>
              </div>
            </article>"""


def main():
    cards = []
    delays = [0, 120, 240, 180, 60, 300, 90, 210, 150, 270]
    for i, course in enumerate(COURSES):
        path = OUTPUT_DIR / f"{course['slug']}.html"
        path.write_text(generate_page(course), encoding="utf-8")
        print(f"Generated: {path.name}")
        cards.append(card_html(course, delays[i % len(delays)]))

    cards_block = "\n\n".join(cards)
    cards_path = OUTPUT_DIR / "_curso_cards_snippet.html"
    cards_path.write_text(cards_block, encoding="utf-8")
    print(f"Cards snippet: {cards_path}")


if __name__ == "__main__":
    main()

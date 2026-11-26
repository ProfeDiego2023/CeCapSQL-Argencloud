<!-- ==== FOOTER GLOBAL CECAP ==== -->

<style>

  .footer-bar {
    position: fixed;
    bottom: 0;
    left: 0;

    width: 100%;

    background: linear-gradient(
      90deg,
      rgba(0,0,0,0.45),
      rgba(221,72,65,0.18),
      rgba(0,0,0,0.45)
    );

    background-size: 200% 200%;
    background-position: left center;

    backdrop-filter: blur(10px);

    border-top: 1px solid rgba(255,255,255,0.15);

    color: #fff;

    font-size: 0.9rem;
    font-weight: 600;

    text-align: center;

    padding: 10px 0;

    z-index: 1000;

    animation: fondoAnimFooter 18s ease-in-out infinite alternate;
  }

  @keyframes fondoAnimFooter {

    0% {
      background-position: left center;
    }

    100% {
      background-position: right center;
    }

  }

  .footer-bar span {
    opacity: 0.9;
    transition: opacity 0.3s;
  }

  .footer-bar span:hover {
    opacity: 1;
    color: #fff;
  }

  .footer-bar a {
    color: #DD4841;
    text-decoration: none;
    font-weight: 700;
    transition: all 0.3s ease;
  }

  .footer-bar a:hover {
    opacity: .75;
    color: #C7E4CF;
  }

  body {
    padding-bottom: 60px;
  }

</style>

<div class="footer-bar">
  © <?= date('Y'); ?> • Diego Caravaca •

  <a href="https://recursoscreativos.net.ar"
     target="_blank"
     rel="noopener noreferrer">

     Recursos Creativos

  </a>

  • Sistema de Gestión V8.26
</div>

<!-- ==== FIN FOOTER GLOBAL CECAP ==== -->
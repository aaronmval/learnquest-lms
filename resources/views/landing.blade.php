<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LearnQuest | Your LMS</title>
  <link rel="icon" type="image/svg+xml" href="/assets/images/LearnQuestLogo.svg">


<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet" href="/css/landingpage.css">
</head>
<body>

<nav class="top">
  <div class="row">
    <div class="brand">
      <div class="brand-mark"><i class="fas fa-graduation-cap"></i></div>
      <span class="brand-name">LearnQuest</span>
    </div>
    <div class="nav-links">
      <a href="#subjects">Subjects</a>
      <a href="#features">How it Works</a>
      <a href="#account">Log In</a>
    </div>
  </div>
</nav>

<header class="hero">
  <div class="wrap">
  <div class="hero-card">
  <div class="hero-grid">
    <div>
      <span class="eyebrow">LearnQuest: Adaptive Learning Management System</span>
      <h1>Master any skill with <span>personalized pathways.</span></h1>
      <p class="lede">LearnQuest uses AI-driven algorithms to tailor your curriculum in real-time, ensuring you focus on what matters most to achieve mastery faster.</p>
      <div class="hero-social">
        <div class="avatar-stack">
          <span class="avatar-sm" style="background:var(--dot-green);">J</span>
          <span class="avatar-sm" style="background:var(--dot-cyan);">M</span>
          <span class="avatar-sm" style="background:var(--dot-orange);">A</span>
          <span class="avatar-sm" style="background:var(--dot-purple);">R</span>
        </div>
        <span class="hero-social-text">Joined by 110 Kapitolyo HS STEM students</span>
      </div>
      <div class="hero-ctas">
        <a href="/login" class="btn btn-lg btn-white">Log in</a>
        <a href="/register" class="btn btn-lg btn-dark">Sign up</a>
      </div>
    </div>

    <div class="radar-wrap">
      <svg class="radar-svg" viewBox="0 0 300 300" xmlns="http://www.w3.org/2000/svg">
        <polygon points="150,70 226.08,125.28 197.04,214.72 102.96,214.72 73.92,125.28" class="radar-grid"/>
        <polygon points="150,90 207.06,131.46 185.28,198.54 114.72,198.54 92.94,131.46" class="radar-grid"/>
        <polygon points="150,110 188.04,137.64 173.52,182.36 126.48,182.36 111.96,137.64" class="radar-grid"/>
        <polygon points="150,130 169.02,143.82 161.76,166.18 138.24,166.18 130.98,143.82" class="radar-grid"/>
        <polygon points="150,50 245.1,119.1 208.8,230.9 91.2,230.9 54.9,119.1" class="radar-grid radar-grid-outer"/>

        <line x1="150" y1="150" x2="150" y2="50" class="radar-axis"/>
        <line x1="150" y1="150" x2="245.1" y2="119.1" class="radar-axis"/>
        <line x1="150" y1="150" x2="208.8" y2="230.9" class="radar-axis"/>
        <line x1="150" y1="150" x2="91.2" y2="230.9" class="radar-axis"/>
        <line x1="150" y1="150" x2="54.9" y2="119.1" class="radar-axis"/>

        <text x="140" y="53" class="radar-scale">10</text>
        <text x="140" y="73" class="radar-scale">8</text>
        <text x="140" y="93" class="radar-scale">6</text>
        <text x="140" y="113" class="radar-scale">4</text>
        <text x="140" y="133" class="radar-scale">2</text>
        <text x="140" y="153" class="radar-scale">0</text>

        <polygon points="150,60 216.6,128.4 197.0,214.7 97.1,222.8 92.9,131.5" class="radar-series radar-orange"/>
        <polygon points="150,90 226.1,125.3 202.9,222.8 108.8,206.6 102.5,134.6" class="radar-series radar-purple"/>
        <polygon points="150,80 235.6,122.2 185.3,198.5 103.0,214.7 83.4,128.4" class="radar-series radar-blue"/>
        <polygon points="150,100 188.0,137.6 185.3,198.5 120.6,190.5 112.0,137.6" class="radar-series radar-navy"/>
      </svg>
    </div>
  </div>
  </div>
  </div>
</header>

<section class="trust-bar">
  <div class="wrap">
    <div class="trust-grid">
      <div class="trust-item">
        <span class="trust-num" data-target="110" data-suffix="+">0</span>
        <span class="trust-label">STEM students enrolled</span>
      </div>
      <div class="trust-item">
        <span class="trust-num" data-target="4.9" data-decimals="1">0</span>
        <span class="trust-label">Average rating</span>
      </div>
      <div class="trust-item">
        <span class="trust-num" data-target="89" data-suffix="%">0</span>
        <span class="trust-label">Course completion rate</span>
      </div>
      <div class="trust-item">
        <span class="trust-num" data-target="4" data-suffix="+">0</span>
        <span class="trust-label">Subjects and counting</span>
      </div>
    </div>
  </div>
</section>

<section class="subjects" id="subjects">
  <div class="wrap">
    <div class="subjects-head">
      <h2>Pick a subject, see the route</h2>
      <span class="eyebrow eyebrow-onDark">4 active workspaces</span>
    </div>

    <div class="tabs" id="tabList">
      <div class="tab active" data-subject="biology"><span class="dot" style="background:var(--dot-green);"></span>General Biology</div>
      <div class="tab" data-subject="chemistry"><span class="dot" style="background:var(--dot-cyan);"></span>Chemistry</div>
      <div class="tab" data-subject="earth"><span class="dot" style="background:var(--dot-orange);"></span>Earth Science</div>
      <div class="tab" data-subject="physics"><span class="dot" style="background:var(--dot-purple);"></span>Physics</div>
    </div>

    <div class="subject-card active" data-subject="biology" style="--accent:var(--dot-green);">
      <h3><span class="dot-lg" style="background:var(--dot-green);"></span>General Biology</h3>
      <p>From cell structure to ecosystems — LearnQuest sequences your units so each one builds on the concept you just understood.</p>
      <ul class="milestone-list">
        <li><i class="fas fa-check-circle done"></i>Cell structure & function</li>
        <li><i class="fas fa-circle-notch next"></i>Genetics & heredity</li>
        <li><i class="fas fa-circle locked"></i>Ecology & ecosystems</li>
      </ul>
    </div>

    <div class="subject-card" data-subject="chemistry" style="--accent:var(--dot-cyan);">
      <h3><span class="dot-lg" style="background:var(--dot-cyan);"></span>Chemistry</h3>
      <p>From balancing equations to titration curves — labs are sequenced so each one builds on the reaction you just understood.</p>
      <ul class="milestone-list">
        <li><i class="fas fa-check-circle done"></i>Atomic structure & periodicity</li>
        <li><i class="fas fa-check-circle done"></i>Chemical bonding</li>
        <li><i class="fas fa-circle-notch next"></i>Reaction rates & equilibrium</li>
      </ul>
    </div>

    <div class="subject-card" data-subject="earth" style="--accent:var(--dot-orange);">
      <h3><span class="dot-lg" style="background:var(--dot-orange);"></span>Earth Science</h3>
      <p>Study plate tectonics, weather systems, and geologic time as connected processes instead of isolated facts.</p>
      <ul class="milestone-list">
        <li><i class="fas fa-check-circle done"></i>Rocks & minerals</li>
        <li><i class="fas fa-circle-notch next"></i>Plate tectonics</li>
        <li><i class="fas fa-circle locked"></i>Weather & climate systems</li>
      </ul>
    </div>

    <div class="subject-card" data-subject="physics" style="--accent:var(--dot-purple);">
      <h3><span class="dot-lg" style="background:var(--dot-purple);"></span>Physics</h3>
      <p>Run the simulation, not just the formula. Every concept ships with an interactive model you can nudge and watch respond.</p>
      <ul class="milestone-list">
        <li><i class="fas fa-check-circle done"></i>Kinematics</li>
        <li><i class="fas fa-circle-notch next"></i>Forces & motion</li>
        <li><i class="fas fa-circle locked"></i>Energy & momentum</li>
      </ul>
    </div>
  </div>
</section>

<section class="features" id="features">
  <div class="wrap">
    <span class="eyebrow eyebrow-onDark">How it works</span>
    <h2 class="features-title">Three Things LearnQuest Does</h2>
    <div class="features-grid">
      <div class="feature">
        <div class="icon-wrap"><i class="fas fa-route"></i></div>
        <h3>Adaptive Curricula</h3>
        <p>AI-driven modules that adjust complexity automatically based on your individual performance and pace.</p>
      </div>
      <div class="feature">
        <div class="icon-wrap"><i class="fas fa-bullseye"></i></div>
        <h3>Goal Alignment</h3>
        <p>Curated learning paths specifically mapped to your career objectives and personal development milestones.</p>
      </div>
      <div class="feature">
        <div class="icon-wrap"><i class="fas fa-chart-line"></i></div>
        <h3>Mastery Analytics</h3>
        <p>Visualize your learning journey with real-time feedback and detailed mastery indicators for every topic.</p>
      </div>
    </div>
  </div>
</section>

<section class="testimonials" id="testimonials">
  <div class="wrap">
    <span class="eyebrow eyebrow-onDark">Why learners stay</span>
    <h2 class="features-title">Real progress, real feedback</h2>
    <div class="testimonials-grid">
      <div class="testimonial-card">
        <p class="testimonial-quote">I used to bounce between random tutorials. LearnQuest actually told me what to study next, and I finally finished a full biology track.</p>
        <div class="testimonial-person">
          <span class="avatar" style="background:var(--dot-green);">JR</span>
          <div>
            <div class="t-name">Jamie R.</div>
            <div class="t-role">Biology track · Month 3</div>
          </div>
        </div>
      </div>
      <div class="testimonial-card">
        <p class="testimonial-quote">The chemistry path adjusted the moment I struggled with equilibrium. It felt like it noticed I was stuck before I even said anything.</p>
        <div class="testimonial-person">
          <span class="avatar" style="background:var(--dot-cyan);">MA</span>
          <div>
            <div class="t-name">Mika A.</div>
            <div class="t-role">Chemistry track · Month 2</div>
          </div>
        </div>
      </div>
      <div class="testimonial-card">
        <p class="testimonial-quote">Seeing the milestone list check off in real time kept me coming back. It's the first study plan I've actually stuck with.</p>
        <div class="testimonial-person">
          <span class="avatar" style="background:var(--dot-purple);">RD</span>
          <div>
            <div class="t-name">Reese D.</div>
            <div class="t-role">Physics track · Month 5</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<footer class="site-footer" id="account">
  <div class="wrap">
    <div class="footer-top">
      <div class="footer-brand">
        <div class="brand">
          <div class="brand-mark"><i class="fas fa-graduation-cap"></i></div>
          <span class="brand-name">LearnQuest</span>
        </div>
        <p>Adaptive learning paths that adjust to your pace, so every session builds on the last.</p>
      </div>

      <div class="footer-col">
        <h4>Explore</h4>
        <ul>
          <li><a href="#subjects">Subjects</a></li>
          <li><a href="#features">How it works</a></li>
        </ul>
      </div>

      <div class="footer-col">
        <h4>Subjects</h4>
        <ul>
          <li><a href="#subjects" class="footer-subject-link" data-subject="biology">Biology</a></li>
          <li><a href="#subjects" class="footer-subject-link" data-subject="chemistry">Chemistry</a></li>
          <li><a href="#subjects" class="footer-subject-link" data-subject="earth">Earth Science</a></li>
          <li><a href="#subjects" class="footer-subject-link" data-subject="physics">Physics</a></li>
        </ul>
      </div>

      <div class="footer-cta">
        <h4>Ready to start mapping your learning?</h4>
        <p>Log in to pick up where you left off, or create an account to start your first route today.</p>
        <div class="footer-actions">
          <a href="/login" class="btn btn-primary">Log in</a>
          <a href="/register" class="btn btn-outline">Sign up</a>
        </div>
        <div class="footer-note">No credit card required</div>
      </div>
    </div>

    <div class="footer-bottom">
      <span>© 2026 LearnQuest. All rights reserved.</span>
      </div>
    </div>
  </div>
</footer>

<script>
  function activateSubject(subject){
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.subject-card').forEach(c => c.classList.remove('active'));
    const tab = document.querySelector('.tab[data-subject="' + subject + '"]');
    const card = document.querySelector('.subject-card[data-subject="' + subject + '"]');
    if(tab) tab.classList.add('active');
    if(card) card.classList.add('active');
  }

  document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => activateSubject(tab.dataset.subject));
  });

  document.querySelectorAll('.footer-subject-link').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      activateSubject(link.dataset.subject);
      document.getElementById('subjects').scrollIntoView({behavior:'smooth'});
    });
  });

  function animateCounter(el){
    const target = parseFloat(el.dataset.target);
    const decimals = parseInt(el.dataset.decimals || 0);
    const suffix = el.dataset.suffix || '';
    const duration = 1200;
    const start = performance.now();
    function step(now){
      const progress = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      el.textContent = (target * eased).toFixed(decimals) + suffix;
      if(progress < 1) requestAnimationFrame(step);
      else el.textContent = target.toFixed(decimals) + suffix;
    }
    requestAnimationFrame(step);
  }

  const trustObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if(entry.isIntersecting){
        animateCounter(entry.target);
        trustObserver.unobserve(entry.target);
      }
    });
  }, {threshold:0.4});

  document.querySelectorAll('.trust-num').forEach(el => trustObserver.observe(el));
</script>

</body>
</html>

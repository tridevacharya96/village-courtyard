import { Link } from 'react-router-dom';
import CmsPage from './CmsPage';

/** About = the built-in "about" CMS page, plus a booking prompt. */
export default function About() {
  return (
    <>
      <CmsPage slug="about" eyebrow="Our Story" />
      <section className="cta section">
        <div className="container">
          <h2>Come and taste the story</h2>
          <p>Lanterns, terracotta and slow-cooked food. Book a courtyard table or order to your door.</p>
          <div className="d-flex justify-content-center gap-2 flex-wrap">
            <Link to="/reservations" className="btn btn-gold">Reserve a table</Link>
            <Link to="/menu" className="btn btn-ghost-light">Order online</Link>
          </div>
        </div>
      </section>
    </>
  );
}

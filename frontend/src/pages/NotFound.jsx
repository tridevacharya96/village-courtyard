import { Link } from 'react-router-dom';
import { useSeo } from '../hooks/useSeo';
import { PageBanner } from '../components/Bits';

export default function NotFound() {
  useSeo('Page not found');
  return (
    <>
      <PageBanner eyebrow="404" title="This page has wandered off" intro="The link may be old or mistyped." />
      <div className="state-box">
        <div className="d-flex justify-content-center gap-2 flex-wrap">
          <Link to="/" className="btn btn-forest">Go to the homepage</Link>
          <Link to="/menu" className="btn btn-outline-forest">See the menu</Link>
        </div>
      </div>
    </>
  );
}

import { api } from '../services/api';
import { useApi } from '../hooks/useApi';
import { useSeo } from '../hooks/useSeo';
import { ErrorState, Loading } from '../components/Bits';
import Hero from '../components/home/Hero';
import { About, Cta, GalleryPreview, Hours, Signature, Specials, Testimonials, WhyUs } from '../components/home/Sections';

/** Section key (Admin → Homepage) → component. Unknown keys are skipped. */
const SECTIONS = { hero: Hero, about: About, signature: Signature, specials: Specials, why_us: WhyUs, gallery: GalleryPreview, testimonials: Testimonials, hours: Hours, cta: Cta };

export default function Home() {
  useSeo(null);
  const { data, error, loading, reload } = useApi((signal) => api.homepage({ signal }), []);

  if (loading) return <Loading rows={4} label="Loading the homepage" />;
  if (error) return <ErrorState error={error} onRetry={reload} />;

  return (
    <>
      {data.sections.map((section) => {
        const Component = SECTIONS[section.key];
        return Component ? <Component key={section.key} section={section} /> : null;
      })}
    </>
  );
}

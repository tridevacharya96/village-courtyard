import { useParams } from 'react-router-dom';
import { api } from '../services/api';
import { useApi } from '../hooks/useApi';
import { useSeo } from '../hooks/useSeo';
import { ErrorState, Loading, PageBanner } from '../components/Bits';
import NotFound from './NotFound';

/**
 * Pages written in Admin → Pages. The HTML is sanitised by the server when it
 * is saved (allow-list of tags, no scripts or event handlers), so it is safe
 * to render here.
 */
export default function CmsPage({ slug: fixedSlug, eyebrow = 'Village Courtyard' }) {
  const params = useParams();
  const slug = fixedSlug || params.slug;
  const { data, error, loading, reload } = useApi((signal) => api.page(slug, { signal }), [slug]);
  const page = data?.page;
  useSeo(page?.meta_title || page?.title, page?.meta_description, page?.banner_image);

  if (loading) return <Loading rows={3} />;
  if (error?.status === 404) return <NotFound />;
  if (error) return <ErrorState error={error} onRetry={reload} />;

  return (
    <>
      <PageBanner eyebrow={eyebrow} title={page.title} image={page.banner_image} />
      <section className="section">
        <div className="container">
          {/* eslint-disable-next-line react/no-danger */}
          <article className="prose" dangerouslySetInnerHTML={{ __html: page.content }} />
        </div>
      </section>
    </>
  );
}

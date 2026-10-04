/** Opening hours table + contact details (+ optional map), from settings. */
export function HoursTable({ hours = [] }) {
  if (!hours.length) return null;
  return (
    <table className="hours-table"><tbody>
      {hours.map((h, i) => <tr key={i}><td>{h.day}</td><td>{h.hours}</td></tr>)}
    </tbody></table>
  );
}

export function ContactList({ s }) {
  const tel = (s.phone || '').replace(/[^\d+]/g, '');
  const wa = (s.whatsapp || '').replace(/\D/g, '');
  return (
    <dl className="contact-list">
      {s.address && <><dt>Address</dt><dd>{s.address}</dd></>}
      {s.phone && <><dt>Phone</dt><dd><a href={`tel:${tel}`}>{s.phone}</a></dd></>}
      {wa && <><dt>WhatsApp</dt><dd><a href={`https://wa.me/${wa}`} target="_blank" rel="noopener noreferrer">Message us</a></dd></>}
      {s.email && <><dt>Email</dt><dd><a href={`mailto:${s.email}`}>{s.email}</a></dd></>}
    </dl>
  );
}

export function MapFrame({ url, title = 'Map showing the restaurant location' }) {
  if (!url || !/^https:\/\//.test(url)) return null;
  return <iframe className="map-frame" src={url} title={title} loading="lazy" referrerPolicy="no-referrer-when-downgrade" allowFullScreen />;
}

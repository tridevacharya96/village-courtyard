import Img from './Img';
import AddToCart from './AddToCart';
import { FoodMark, Spice } from './Bits';
import { money } from '../lib/format';

/** Printed-menu style row: photo, name with dotted leader, price, add. */
export default function MenuRow({ item }) {
  return (
    <div className="menu-row">
      <Img className="thumb" src={item.image} alt="" />
      <h3><FoodMark type={item.food_type} /><span>{item.name}</span><span className="leader" aria-hidden="true" /></h3>
      <div className="row-actions">
        <span className="price">{money(item.price)}</span>
        <AddToCart item={item} compact />
      </div>
      <p>{item.description} <Spice level={item.spice_level} /></p>
    </div>
  );
}

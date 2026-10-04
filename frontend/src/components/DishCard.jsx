import Img from './Img';
import AddToCart from './AddToCart';
import { FoodMark, Spice } from './Bits';
import { money } from '../lib/format';

export default function DishCard({ item }) {
  return (
    <article className="dish-card" data-reveal>
      <div className="media"><Img src={item.image} alt={item.name} /></div>
      <div className="body">
        <h3><FoodMark type={item.food_type} />{item.name}</h3>
        <p>{item.description}</p>
        <div className="foot">
          <span className="price">{money(item.price)}</span>
          <Spice level={item.spice_level} />
          <AddToCart item={item} />
        </div>
      </div>
    </article>
  );
}

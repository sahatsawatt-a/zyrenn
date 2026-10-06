import {
    Baby,
    Banknote,
    BedDouble,
    Beer,
    Bell,
    Bike,
    BookOpen,
    Bookmark,
    Briefcase,
    Building2,
    Bus,
    CakeSlice,
    Calendar,
    Camera,
    Car,
    Castle,
    Church,
    Clapperboard,
    Clock,
    Coffee,
    Compass,
    Croissant,
    Crown,
    Dumbbell,
    Fish,
    Flag,
    Flower2,
    Fuel,
    Gamepad2,
    Gem,
    Gift,
    Globe,
    GraduationCap,
    Heart,
    Hospital,
    House,
    IceCreamCone,
    Landmark,
    Lightbulb,
    Luggage,
    Map as MapIcon,
    MapPin,
    Mountain,
    Music,
    Palette,
    PawPrint,
    Pill,
    Pizza,
    Plane,
    School,
    Scissors,
    Ship,
    Shirt,
    ShoppingBag,
    ShoppingCart,
    Soup,
    Sparkles,
    Star,
    Stethoscope,
    Store,
    Sun,
    Tag,
    Tent,
    Ticket,
    TrainFront,
    TreePalm,
    TreePine,
    Trees,
    Trophy,
    Umbrella,
    Users,
    UtensilsCrossed,
    Waves,
    Wine,
    Wrench,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';

// The icons a person can give a thing of theirs -- a list of places, and
// whatever else wants one. Each is stored by its name, which is Lucide's own,
// so the server keeps a short string and an unknown name falls back.

export interface IconChoice {
    name: string;
    label: string;
    icon: Component;
}

export interface IconGroup {
    label: string;
    icons: IconChoice[];
}

const choice = (name: string, label: string, icon: Component): IconChoice => ({
    name,
    label,
    icon,
});

export const ICON_GROUPS: IconGroup[] = [
    {
        label: 'Places',
        icons: [
            choice('bookmark', 'Bookmark', Bookmark),
            choice('map-pin', 'Pin', MapPin),
            choice('star', 'Star', Star),
            choice('heart', 'Heart', Heart),
            choice('flag', 'Flag', Flag),
            choice('house', 'Home', House),
            choice('building-2', 'Office', Building2),
            choice('store', 'Shop', Store),
            choice('landmark', 'Landmark', Landmark),
            choice('castle', 'Castle', Castle),
            choice('church', 'Temple', Church),
            choice('school', 'School', School),
            choice('hospital', 'Hospital', Hospital),
            choice('tent', 'Camp', Tent),
        ],
    },
    {
        label: 'Food & drink',
        icons: [
            choice('utensils-crossed', 'Restaurant', UtensilsCrossed),
            choice('coffee', 'Coffee', Coffee),
            choice('croissant', 'Bakery', Croissant),
            choice('pizza', 'Pizza', Pizza),
            choice('soup', 'Noodles', Soup),
            choice('cake-slice', 'Dessert', CakeSlice),
            choice('ice-cream-cone', 'Ice cream', IceCreamCone),
            choice('beer', 'Bar', Beer),
            choice('wine', 'Wine', Wine),
        ],
    },
    {
        label: 'Travel',
        icons: [
            choice('plane', 'Flight', Plane),
            choice('train-front', 'Train', TrainFront),
            choice('bus', 'Bus', Bus),
            choice('car', 'Car', Car),
            choice('bike', 'Bike', Bike),
            choice('ship', 'Boat', Ship),
            choice('fuel', 'Fuel', Fuel),
            choice('bed-double', 'Hotel', BedDouble),
            choice('luggage', 'Luggage', Luggage),
            choice('ticket', 'Ticket', Ticket),
            choice('camera', 'Sights', Camera),
            choice('compass', 'Explore', Compass),
            choice('map', 'Map', MapIcon),
            choice('globe', 'World', Globe),
        ],
    },
    {
        label: 'Outdoors',
        icons: [
            choice('mountain', 'Mountain', Mountain),
            choice('trees', 'Park', Trees),
            choice('tree-pine', 'Forest', TreePine),
            choice('tree-palm', 'Beach', TreePalm),
            choice('waves', 'Water', Waves),
            choice('sun', 'Sun', Sun),
            choice('umbrella', 'Umbrella', Umbrella),
            choice('flower-2', 'Garden', Flower2),
            choice('paw-print', 'Animals', PawPrint),
            choice('fish', 'Fish', Fish),
        ],
    },
    {
        label: 'Things to do',
        icons: [
            choice('shopping-bag', 'Shopping', ShoppingBag),
            choice('shopping-cart', 'Groceries', ShoppingCart),
            choice('gift', 'Gift', Gift),
            choice('music', 'Music', Music),
            choice('clapperboard', 'Film', Clapperboard),
            choice('gamepad-2', 'Games', Gamepad2),
            choice('dumbbell', 'Gym', Dumbbell),
            choice('book-open', 'Books', BookOpen),
            choice('palette', 'Art', Palette),
            choice('shirt', 'Clothes', Shirt),
            choice('scissors', 'Salon', Scissors),
            choice('pill', 'Pharmacy', Pill),
            choice('stethoscope', 'Doctor', Stethoscope),
            choice('wrench', 'Repair', Wrench),
            choice('banknote', 'Money', Banknote),
        ],
    },
    {
        label: 'Other',
        icons: [
            choice('briefcase', 'Work', Briefcase),
            choice('graduation-cap', 'Study', GraduationCap),
            choice('users', 'People', Users),
            choice('baby', 'Kids', Baby),
            choice('calendar', 'Calendar', Calendar),
            choice('clock', 'Clock', Clock),
            choice('bell', 'Bell', Bell),
            choice('tag', 'Tag', Tag),
            choice('lightbulb', 'Idea', Lightbulb),
            choice('sparkles', 'Sparkles', Sparkles),
            choice('gem', 'Gem', Gem),
            choice('crown', 'Crown', Crown),
            choice('trophy', 'Trophy', Trophy),
            choice('zap', 'Zap', Zap),
        ],
    },
];

const BY_NAME = new Map(
    ICON_GROUPS.flatMap((group) => group.icons).map((each) => [
        each.name,
        each,
    ]),
);

export const DEFAULT_ICON = 'bookmark';

/** The colours offered beside the icons; any other can be mixed. */
export const ICON_COLORS = [
    '#3b82f6',
    '#06b6d4',
    '#10b981',
    '#84cc16',
    '#f59e0b',
    '#f97316',
    '#ef4444',
    '#ec4899',
    '#8b5cf6',
    '#64748b',
];

/** The icon stored by a name, or the default for one not (or no longer) in the set. */
export const iconNamed = (name: string | null | undefined): IconChoice =>
    BY_NAME.get(name ?? '') ?? BY_NAME.get(DEFAULT_ICON)!;

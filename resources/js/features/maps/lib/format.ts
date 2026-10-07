import {
    Bike,
    BusFront,
    Car,
    CarTaxiFront,
    Footprints,
    MoveRight,
    TrainFront,
    TramFront,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { TravelMode } from '@/lib/maps';

// How the maps pages say distances, times and ways of getting about.

export const travelModes: {
    id: TravelMode;
    label: string;
    verb: string;
    icon: Component;
}[] = [
    { id: 'pedestrian', label: 'Walk', verb: 'walk', icon: Footprints },
    { id: 'auto', label: 'Drive', verb: 'drive', icon: Car },
    { id: 'bicycle', label: 'Cycle', verb: 'cycle', icon: Bike },
];

export const travelMode = (id: TravelMode) =>
    travelModes.find((mode) => mode.id === id) ?? travelModes[0];

export type LegMode = 'metro' | 'bus' | 'taxi' | 'train' | 'walk' | 'other';

/** The ways a leg can be typed in as, where no router knows -- Metro, above all. */
export const legModes: { id: LegMode; label: string; icon: Component }[] = [
    { id: 'metro', label: 'Metro', icon: TramFront },
    { id: 'bus', label: 'Bus', icon: BusFront },
    { id: 'taxi', label: 'Taxi', icon: CarTaxiFront },
    { id: 'train', label: 'Train', icon: TrainFront },
    { id: 'walk', label: 'Walk', icon: Footprints },
    { id: 'other', label: 'Other', icon: MoveRight },
];

export const legMode = (id: LegMode) =>
    legModes.find((mode) => mode.id === id) ?? legModes[legModes.length - 1];

export const formatDistance = (km: number) =>
    km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(km < 10 ? 1 : 0)} km`;

export const formatDuration = (seconds: number) => {
    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${Math.max(minutes, 1)} min`;
    }

    return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
};

/** A cost with its currency, e.g. "¥1,068". */
export const formatMoney = (currency: string, amount: number) =>
    `${currency}${Math.round(amount * 100) / 100 === Math.round(amount) ? Math.round(amount).toLocaleString() : amount.toLocaleString()}`;

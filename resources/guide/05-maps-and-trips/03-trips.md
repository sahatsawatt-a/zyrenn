# Plan a trip

A trip is a plan for travel, day by day: the flight in and the flight home, the hotels, and each day's stops in the order you will see them, with the way between them worked out.

![A trip's day, its stops in order with the way between them](/images/guide/trips.png)

## Start one

Press **New trip** in **Trips**. Under **Trip**, give it its **First day** and its **Currency**, such as ¥, $ or THB. Change the first day later, and the hotels and flights move with it.

## Flights and hotels

- **Add flights**: the flight in (**Arriving**) and the flight home (**Flying home**), each with its airport and time. The first day starts after the transfer from the airport.
- **Add a hotel**: where it is, check-in and check-out, and what it costs. Its nights are counted for you.
- **Days start and end at the hotel**: each day's way begins and ends at the hotel booked for that night.

## A day

Add places to a day from the search, by clicking the map, or from your saved places. Each stop has how long is spent there, what it costs, and a note, such as "Tickets at gate 2, closes early on Sundays".

- **Getting about** chooses walking, driving or cycling for the day.
- **Quickest order** reorders the day's stops for the shortest way round.
- **Opening hours** looks up the hours of the stops not yet checked, from Google, to see which are open when you get there.
- **Add a rest** puts a break into the day: where you are, or back at the hotel.
- Each stop shows when you arrive and leave, from the day's start time and the way between.

## The way between two stops

It is worked out for you. To say how you will really go, such as "Line 2 to Lujiazui, exit 6", press the pencil beside it and give its minutes and cost. **Transit** opens the way in Amap or Google Maps.

## The whole trip

**All days** shows every day on the map in a colour of its own. The trip's total cost is at the top: stops, rides and hotels together.

:::callout 💡
A note can show a whole trip or one day of it with **/trip**, and a table or a note can read its costs: `trip("Kyoto").day(2).cost`, `trip("Kyoto").total_cost`.
:::

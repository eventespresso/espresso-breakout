#Espresso Breakouts

Espresso Breakouts is an addon for Event Espresso makes it possible to have attendees of a main event register for various "breakout" sessions and track accordingly

Note: This was developed initially for a very specific use case and was not intended for wide distribution.  We may or may not do additional development on this so take it as is.

##A Brief Overview of how it works.

1.  A "Main Event" is created which is the event that tickets are purchased for.
2.  Create Event Categories for the different time slots (just name them accordingly, i.e. "9:30am - 10:30am" etc.)
3.  In the Addon settings is a place to set what the main event is (registration ids from this event will be used to authorize and verify registration for breakout sessions). There is also a place where the user selects which event categories are used for session options.
4.  Then the user creates events for all the sessions they are offering for the various breakouts. When creating the events they assign them to whatever breakout categories the session is offered (and it might be multiple "time slots".
5.  The user sets up a WordPress page and adds a special shortcode that will be where the breakout stuff gets generated. Then they get the url for that page and modify the confirmation emails for their main event so that it includes the registration id and a link to the breakout registration page. 
6.  When registrants visit the breakout registration page they are presented with a form to enter their registration id from the main event and the system validates that ID (makes sure it matches a registration AND that the number of breakout registrations using that id has not exceeded the tickets atttached to the id)
7.  If validated, the attendee is presented with a simple form listing the time slots with a drop down of the events for each time slot that they can select from. The form also contains minimal information (First Name, Last Name, Email) and currently does NOT hook into the EE Questions/Answers system. It saves the data directly to the attendee table.
8.  When the attendee submits their information they are presented with a summary of the "breakouts" they registered for and a simple text based email is sent to them to confirm their registration.

If you want you can view a walkthrough of the registration process and demo of "some" of the admin facing info that's available in a video I made in the following youtube video

 http://youtu.be/WfJVWGr2-f8
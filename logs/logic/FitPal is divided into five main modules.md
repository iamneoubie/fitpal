FitPal is divided into five main modules: the Shared, Customer, Restaurant, Delivery Rider, and Administrator modules. Each module has its own purpose, but they are connected through one centralized database.

The Customer Module handles browsing, cart, checkout, and profiles. The Restaurant Module manages incoming and preparing orders. The Rider Module handles deliveries and status updates, while the Administrator Module manages users and monitors the system. The Shared Module contains common features used by all modules.

For database integration, FitPal uses MySQL with PDO and prepared statements. It also uses foreign keys, stored procedures, and database views to keep the data organized and consistent.

FitPal is also mostly self-contained, meaning it does not depend on external APIs. Instead, it uses internal AJAX and JSON to update information without constantly reloading the page.

The basic data flow starts when a customer places an order. The restaurant receives and prepares it, then an available rider delivers it. The rider updates the status, and the customer can see the updates.

Lastly, FitPal uses role-based authentication and security features such as CSRF protection, server-side validation, prepared statements, and password hashing.

Overall, Chapter 4 shows that FitPal is organized, connected, and secure, allowing the different users to work together through one system

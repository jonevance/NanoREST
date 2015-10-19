# NanoREST

NanoREST is an [Action Domain Responder](https://github.com/pmjones/adr/blob/master/README.md) nano-framework.  Why a "nano" framework?  NanoREST accomplishes the task of wrapping your PHP application as a compliant ADR system in less than 350 lines of PHP code!

## Requirements

NanoREST requires PHP 5.0 or later.

## Installation

Simply download the nanorest.php source file to your file system and include in your main API file (presumably index.php) like this:

```php
require_once("nanorest.php");
```
    
## Usage

An ADR function (or object method) will receive as arguments a reference to the NanoREST object followed by any arguments you request for it during function / method registration.  The function or method should return an array ready to be output by the API as a JSON block, or true / false to indicate success or failure of a non-returning function.

An example controller class:

```php
class Controller
{
  public function getCustomerList(&$oRest)
  {
    // get the list of customers into $aCustomers
    
    return array("Customers" => $aCustomers);
  }
  
  public function addCustomer(&$oRest, $sName, $sEmail, $sPassword)
  {
    // add customer to the database
    return true;
  }
  
  public function updateCustomer(&$oRest, $args)
  {
    $id = $args["ID"];
    
    if (isset($args["Name"]))
    {
      // update customer name
    }
  
    if (isset($args["Email"]))
    {
      // update customer email
    }
  
    if (isset($args["Password"]))
    {
      // update customer password
    }
  
    return true;
  }
  
  public function deleteCustomer(&$oRest, $sUser, $sPassword, $id)
  {
    // validate that $sUser / $sPassword point to a valid user
    
    // delete customer with ID $id
    
    return true;
  }
  
}
```

Simply register functions or object methods for each of your REST endpoints:


```php
$app = new NanoREST();

$app->get("/Customers", "Controller::getCustomerList");
$app->post("/Customers", "Controller::addCustomer", "Name", "Email", "Password");
$app->put("/Customers/{ID}", "Controller::updateCustomer", "[All]");
$app->delete("/Customers/{ID}", "Controller::deleteCustomer", "[Auth]", "ID");

$app->run();

```

Note that after specifying the function / method name, you can indicate what arguments the function requires.  These arguments can come from the URL path (e.g. the ID variable sent to deleteCustomer) the query data and any POSTed or PUT data.  There are two special arguments:

* [All] - send all the arguments from the URL path, query string, POSTed and PUT data as an associative array
* [Auth] - decode and send the username and password from the "Authorization" header as "username, password"

## Output

NanoREST will append "Status: SUCCESS" to any successful response and then output as a JSON block, like this:

```json
{
  "Customers":
  [
    {
      "ID": 100,
      "Name": "Joe Smith",
      "Email": "joe.smith@emailprovider.com"
    },
    {
      "ID": 101,
      "Name": "Jason Baker",
      "Email": "jason.baker@emailprovider.com"
    },
    {
      "ID": 102,
      "Name": "John Doe",
      "Email": "john.doe@emailprovider.com"
    }
  ],
  "Status": "SUCCESS"
}
```

## Errors

NanoREST will catch any exceptions and output them as errors.  If the exception code is a valid [HTTP status code](http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html) then that code will be used as the HTTP status code, and if not 500 (internal server error) will be sent.

For example, the following:

```php
function getCustomer(&$oRest, $id)
{
  throw new Exception("No such customer", 404);
}
```

Will result in the following output:

```json
HTTP/1.1 404 No such customer
Date: Mon, 19 Oct 2015 20:37:51 GMT
Content-Type: application/json

{"Status": "ERROR", "Code": 404, "Message": "No such customer"}
```

## Handling Caching Requirements

In your registered function / method you can use the reference to the NanoREST object to specify the last modification date of the requested document so as to allow the framework to handle any necessary headers.  The NanoREST::setModified function takes as arguments a unique identifier for the document and the last modification date (as a timestamp value or a common date-time string).

For example, this:

```php
function getCustomer(&$oRest, $id)
{
  // assume $id = 100
  $oRest->setModified($id, "2015-01-01 12:10:00");
  
  return array("Customer" => array("Name": "Joe Smith", "Email": joe.smith@emailprovider.com"));
}
```

Would result in this output:

```json
HTTP/1.1 200 OK
Date: Mon, 19 Oct 2015 20:42:44 GMT
E-Tag: 20150101121000100
Content-Length: 97
Content-Type: application/json

{"Customer": {"Name": "Joe Smith", "Email": "joe.smith@emailprovider.com"}, "Status": "SUCCESS"}

```

# Unysis Marketplace

Internal catalogue where the UNYSIS team publishes the AI Models and Scripts that run on UNYSIS Boxes, and from which RPA-TOOL fetches them.

## Language

### Catalogue

**AI Model**:
A trained inference model published on its own, independent of any Script.
_Avoid_: Model (bare), weights, network

**Script**:
A packaged automation sequence for one Machine Model, bundling its flow definition with whatever AI models and libraries it needs to run. One Machine Model may have many Scripts.
_Avoid_: FlowChart Script, flow, workflow, recipe

**Preview Image**:
A picture attached to a Script (a flow diagram or screenshot) so Team Members and Customer Users can recognise it before downloading.
_Avoid_: Thumbnail, screenshot, cover

**Revision**:
An immutable, sequentially numbered upload of an AI Model or Script, carrying a change note and the Team Member who uploaded it.
_Avoid_: Version, release, build

**Revision Status**:
Where a Revision sits in its life: draft, released, or deprecated. Only released Revisions are offered to RPA-TOOL by default.

### Machines

**Machine Model**:
A specific make of equipment that a Script targets; the primary way the catalogue is organised.
_Avoid_: Model (bare), machine, equipment, tool

**Machine Brand**:
A reusable tag naming the manufacturer of a Machine Model.
_Avoid_: Vendor, maker, manufacturer

**Customer**:
A company that owns UNYSIS Boxes and has its own Customer Users. An AI Model or Script may be labelled with the Customer it was made for; the label is a secondary filter, never an access wall.
_Avoid_: Client, site, account, tenant

### People and consumers

**Team Member**:
A UNYSIS staff user who signs in to the Marketplace to upload and manage catalogue entries.
_Avoid_: User (bare), uploader, admin

**Customer User**:
A person belonging to one Customer who signs in through RPA-TOOL to reach the catalogue; never has access to the Marketplace admin.
_Avoid_: User (bare), client login, API user

**RPA-TOOL**:
The separate UNYSIS application that reads the catalogue and downloads Revisions through the Marketplace API.

**UNYSIS Box**:
A deployed UNYSIS edge device running RPA-TOOL, identified to the Marketplace by its motherboard UUID and belonging to one Customer.
_Avoid_: AI Box, device, client, edge box, machine

**Download**:
One recorded fetch of a Revision's file, attributed to the Customer User who requested it and the UNYSIS Box it was requested from.
_Avoid_: Pull, fetch, install
